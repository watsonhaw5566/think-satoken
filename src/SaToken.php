<?php

namespace satoken;

use Ramsey\Uuid\Uuid;
use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;
use think\cache\driver\Redis as RedisDriver;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Request;

/**
 * Think-SaToken - 基于 PHP 实现的 SaToken 权限认证框架
 * 实现 Java SaToken 的核心功能
 */
class SaToken implements SatokenInterface
{
    /**
     * 默认配置存储
     *
     * @var array<string, mixed>
     */
    protected static $config = [
        'token_name' => '', // 自定义 Token name 名称
        'timeout' => 86400, // Token 有效期，单位秒
        'is_concurrent' => true, // 是否允许同一账号多地登录（false 等价于 max_login_count=1）
        'max_login_count' => 10, // 同一账号最大登录数量
        'auto_renew' => true, // 是否启用滑动续期
        'renew_threshold' => 0.3, // 滑动续期阈值：剩余时间低于此比例才触发续期 (0~1，默认 30%)
    ];

    /**
     * 缓存当前驱动是否为 Redis（避免每次检测都反射驱动类）
     *
     * @var bool|null
     */
    protected static $isRedisDriver = null;

    /**
     * 检测当前缓存驱动是否为 Redis
     * 仅当驱动为 think\cache\driver\Redis 时，才启用分布式锁进行原子操作
     *
     * @return bool
     */
    public static function isRedisDriver(): bool
    {
        if (self::$isRedisDriver !== null) {
            return self::$isRedisDriver;
        }

        try {
            $driver = Cache::store();
            $isRedis = $driver instanceof RedisDriver;

            if (!$isRedis && is_object($driver)) {
                // 兜底：检查类名中是否包含 redis（兼容可能的自定义驱动）
                $className = get_class($driver);
                $isRedis = stripos($className, 'redis') !== false;
            }

            self::$isRedisDriver = $isRedis;

            return $isRedis;
        } catch (\Throwable $e) {
            // 若获取驱动失败（例如容器未初始化），视为非 Redis 模式
            self::$isRedisDriver = false;

            return false;
        }
    }

    /**
     * 重置驱动检测状态（主要用于测试或驱动切换场景）
     */
    public static function resetDriverDetection(): void
    {
        self::$isRedisDriver = null;
    }

    /**
     * 获取 Redis 原生句柄
     *
     * @return object|null 返回 Redis 实例或 Predis 客户端，非 Redis 驱动时返回 null
     */
    protected static function getRedisHandler()
    {
        if (!self::isRedisDriver()) {
            return null;
        }

        try {
            $driver = Cache::store();

            return $driver->handler();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 尝试获取分布式锁（仅 Redis 模式下生效，其他驱动返回 true 表示"无需锁"）
     *
     * 使用 Redis 的 SET NX EX 原语确保加锁的原子性。设置合理的锁超时时间
     * 防止死锁。即使调用方忘记释放锁，Redis 也会在超时后自动释放。
     *
     * @param string $lockKey 锁的标识（如 'login:1001'）
     * @param int    $ttl     锁超时时间（秒），默认 5 秒
     * @param int    $waitMs  最大等待时间（毫秒），默认 200ms；超过则获取失败
     * @return bool 是否成功获取锁；非 Redis 驱动始终返回 true
     */
    protected static function acquireLock(string $lockKey, int $ttl = 5, int $waitMs = 200): bool
    {
        if (!self::isRedisDriver()) {
            return true;
        }

        $redis = self::getRedisHandler();
        if ($redis === null) {
            return true;
        }

        $cacheKey = 'satoken:lock:'.$lockKey;
        $startTime = (int) (microtime(true) * 1000);

        while ((int) (microtime(true) * 1000) - $startTime < $waitMs) {
            // SET NX EX 原子操作：key 不存在时才写入，并同时设置过期时间
            try {
                $result = $redis->set($cacheKey, '1', ['nx', 'ex' => $ttl]);
            } catch (\Throwable $e) {
                // 某些 Redis 客户端或扩展的参数格式不同，尝试其他写法
                try {
                    $result = $redis->setnx($cacheKey, '1');
                    if ($result) {
                        $redis->expire($cacheKey, $ttl);
                    }
                } catch (\Throwable $e2) {
                    $result = false;
                }
            }

            if ($result) {
                return true;
            }

            usleep(10000); // 等待 10ms 再重试
        }

        return false;
    }

    /**
     * 释放分布式锁
     *
     * @param string $lockKey 锁的标识，需与 acquireLock 调用时一致
     */
    protected static function releaseLock(string $lockKey): void
    {
        if (!self::isRedisDriver()) {
            return;
        }

        $redis = self::getRedisHandler();
        if ($redis === null) {
            return;
        }

        try {
            $cacheKey = 'satoken:lock:'.$lockKey;
            $redis->del($cacheKey);
        } catch (\Throwable $e) {
            // 释放锁失败不影响业务逻辑
        }
    }

    /**
     * 计算实际最大登录数量
     * is_concurrent=false 等价于 max_login_count=1
     *
     * @param array<string, mixed> $config
     */
    private static function resolveMaxLoginCount(array $config): int
    {
        if (empty($config['is_concurrent'])) {
            return 1;
        }

        $max = isset($config['max_login_count']) ? (int) $config['max_login_count'] : 1;

        return $max > 0 ? $max : 1;
    }

    /**
     * 清理 token 列表：过滤掉已失效的 token，并删除其 tokenKey
     *
     * @param array<int, mixed> $tokenList
     * @return array<int, string> 清理后的有效 token 列表
     */
    private static function cleanTokenList(array $tokenList): array
    {
        $cleaned = [];
        foreach ($tokenList as $t) {
            if (! is_string($t) || $t === '') {
                continue;
            }
            if (Cache::has("satoken:token:$t")) {
                $cleaned[] = $t;
            }
        }

        return $cleaned;
    }

    /**
     * 从 token 列表中安全地移除指定 token
     *
     * @param array<int, mixed> $tokenList
     * @param string $token
     * @return array<int, string>
     */
    private static function removeTokenFromList(array $tokenList, string $token): array
    {
        $result = [];
        foreach ($tokenList as $t) {
            if (is_string($t) && $t !== $token) {
                $result[] = $t;
            }
        }

        return $result;
    }

    /**
     * 登录功能
     * 将loginId融入token中，并处理并发登录
     * 当缓存驱动为 Redis 时，使用分布式锁保护 loginIdKey 的读写操作，
     * 防止并发登录时出现竞态条件。
     *
     * @param int $loginId 用户登录ID
     * @param array<string, mixed> $extra 额外自定义内容
     * @return string 生成的token
     */
    public static function login(int $loginId, array $extra = []): string
    {
        $config = self::getConfig();
        $timeout = (int) $config['timeout'];
        $maxCount = self::resolveMaxLoginCount($config);

        // 创建token
        $token = self::createToken();

        $tokenKey = "satoken:token:$token";
        $loginIdKey = "satoken:loginId:$loginId";
        $lockKey = 'login:'.$loginId;

        // Redis 模式下加锁，防止并发登录时的竞态条件
        $locked = self::acquireLock($lockKey, 5, 500);

        try {
            // 读取并清理 token 列表（统一用数组存储）
            $raw = Cache::get($loginIdKey);
            if (is_array($raw)) {
                $tokenList = self::cleanTokenList($raw);
            } elseif (is_string($raw) && $raw !== '') {
                // 兼容历史非并发模式下的字符串映射
                $tokenList = self::cleanTokenList([$raw]);
            } else {
                $tokenList = [];
            }

            // 如果超过最大登录数量，从最早的开始踢出
            $tokenList[] = $token;

            while (count($tokenList) > $maxCount) {
                $oldestToken = array_shift($tokenList);
                if (is_string($oldestToken)) {
                    Cache::delete("satoken:token:$oldestToken");
                }
            }

            // 存储 token 列表（统一用数组）：使用列表中 token 的最小剩余时间作为 TTL
            $ttl = self::getMinRemainingTime($tokenList, $timeout);
            Cache::set($loginIdKey, $tokenList, $ttl);

            // 存储token信息，包含loginId与自定义内容
            $tokenInfo = [
                'loginId' => $loginId,
                'create_time' => time(),
                'expire_time' => time() + $timeout,
                'extra' => $extra,
            ];

            Cache::set($tokenKey, $tokenInfo, $timeout);
        } finally {
            if ($locked && self::isRedisDriver()) {
                self::releaseLock($lockKey);
            }
        }

        return $token;
    }

    public static function createToken(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private static function getConfig(): array
    {
        $satokenConfig = Config::get('satoken');
        if (! is_array($satokenConfig)) {
            $satokenConfig = [];
        }

        $merged = [];
        foreach (array_merge(self::$config, $satokenConfig) as $key => $value) {
            $merged[(string) $key] = $value;
        }

        return $merged;
    }

    /**
     * 解析 token：如果参数为空则从请求中获取
     *
     * @param  string|null  $token
     * @return string|null 解析后的 token，无法获取时返回 null
     */
    private static function resolveToken(?string $token): ?string
    {
        if (empty($token)) {
            $token = self::getToken();
        }

        return empty($token) ? null : $token;
    }

    /**
     * 验证 token 格式并从缓存获取 tokenInfo（不触发续期）
     *
     * @param  string  $token  已解析的 token
     * @return array<string, mixed>|null 格式有效且缓存存在时返回 tokenInfo，否则返回 null
     */
    private static function fetchTokenInfo(string $token): ?array
    {
        if (! self::validateTokenFormat($token)) {
            return null;
        }

        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);

        return is_array($tokenInfo) ? $tokenInfo : null;
    }

    /**
     * 从 tokenInfo 中提取并验证 loginId
     *
     * @param  array<string, mixed>  $tokenInfo
     * @return int|null 有效时返回 loginId，无效时返回 null
     */
    private static function extractLoginId(array $tokenInfo): ?int
    {
        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            return null;
        }

        return $tokenInfo['loginId'];
    }

    /**
     * 验证 token 并返回完整的 tokenInfo，失败时抛出对应异常
     *
     * 用于统一消除 checkLogin/getCurrentLoginId/getTokenInfo 中的重复代码。
     *
     * @param  string  $token  已解析且非空的 token
     * @return array<string, mixed> 有效的 tokenInfo
     *
     * @throws TokenInvalidException 格式无效或缓存中不存在
     */
    private static function getValidTokenInfo(string $token): array
    {
        if (! self::validateTokenFormat($token)) {
            throw new TokenInvalidException('无效的token格式');
        }

        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            throw new TokenInvalidException('无效的token');
        }

        return $tokenInfo;
    }

    /**
     * 从 tokenInfo 中读取 loginId，若缺失或无效则抛出异常
     *
     * @param  array<string, mixed>  $tokenInfo
     * @return int
     *
     * @throws TokenInvalidException
     */
    private static function extractLoginIdOrThrow(array $tokenInfo): int
    {
        $loginId = self::extractLoginId($tokenInfo);
        if ($loginId === null) {
            throw new TokenInvalidException('token信息不完整');
        }

        return $loginId;
    }

    /**
     * 登出功能
     *
     * @param string|null $token 用户token
     * @return bool 是否登出成功
     */
    public static function logout(?string $token = null): bool
    {
        $token = self::resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = self::fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $loginId = self::extractLoginId($tokenInfo);
        if ($loginId === null) {
            return false;
        }

        return self::removeToken($token, $loginId);
    }

    /**
     * 计算 token 列表中所有 token 的最小剩余有效秒数
     * 用于设置 loginIdKey 的 TTL，确保 loginIdKey 不会晚于任何 tokenKey 过期
     *
     * @param array<int, string> $tokenList 有效的 token 列表
     * @param int $fallback 当无法从 tokenInfo 中获取 expire_time 时的回退值
     * @return int 最小剩余秒数（至少为 1）
     */
    private static function getMinRemainingTime(array $tokenList, int $fallback): int
    {
        $min = $fallback;
        foreach ($tokenList as $t) {
            $info = Cache::get("satoken:token:$t");
            if (is_array($info) && ! empty($info['expire_time'])) {
                $remain = (int) $info['expire_time'] - time();
                if ($remain > 0 && $remain < $min) {
                    $min = $remain;
                }
            }
        }
        return max(1, $min);
    }

    /**
     * 从缓存中移除 token（同时清理 loginId 映射）
     * 统一使用数组存储，不再依赖 is_concurrent 配置分支
     * 当缓存驱动为 Redis 时，使用分布式锁保护 loginIdKey 的读写操作。
     *
     * @param  string  $token  要移除的 token
     * @param  int  $loginId  对应的用户ID
     * @return bool 始终返回 true
     */
    private static function removeToken(string $token, int $loginId): bool
    {
        $config = self::getConfig();
        $timeout = (int) $config['timeout'];
        $loginIdKey = "satoken:loginId:$loginId";
        $lockKey = 'login:'.$loginId;

        // Redis 模式下加锁，防止并发登出/踢出时的竞态条件
        $locked = self::acquireLock($lockKey, 5, 200);

        try {
            // 统一用数组方式处理（兼容历史字符串格式）
            $raw = Cache::get($loginIdKey);
            if (is_array($raw)) {
                $tokenList = self::removeTokenFromList($raw, $token);
            } elseif (is_string($raw) && $raw !== '') {
                $tokenList = $raw === $token ? [] : [$raw];
            } else {
                $tokenList = [];
            }

            if (empty($tokenList)) {
                Cache::delete($loginIdKey);
            } else {
                // 使用列表中 token 的最小剩余时间作为 TTL，避免 loginIdKey 晚于 tokenKey 过期
                $ttl = self::getMinRemainingTime($tokenList, $timeout);
                Cache::set($loginIdKey, $tokenList, $ttl);
            }

            // 删除token信息
            Cache::delete("satoken:token:$token");
        } finally {
            if ($locked && self::isRedisDriver()) {
                self::releaseLock($lockKey);
            }
        }

        return true;
    }

    private static function getToken(): ?string
    {
        $config = self::getConfig();
        if (!empty($config['token_name'])) {
            $headerValue = Request::header((string) $config['token_name']);
            if (is_string($headerValue) && $headerValue !== '') {
                return $headerValue;
            }
        }
        $authorization = Request::header('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            return preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1 ? (string) $m[1] : null;
        }

        return null;
    }

    public static function validateTokenFormat(string $token): bool
    {
        // 先快速校验长度，避免超长字符串进入正则引擎
        if (strlen($token) !== 36) {
            return false;
        }

        // 严格验证 UUID v4 格式（第三段以 4 开头，第四段变体为 8|9|a|b）
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token);
    }

    /**
     * 滑动续期：如果开启 auto_renew 且剩余时间低于阈值，则刷新 TTL
     * 仅在需要续期时才写缓存，避免每次请求都产生写操作
     *
     * @param string $token 已验证格式的 token
     * @param array<string, mixed> $tokenInfo 当前 token 信息
     */
    private static function renewIfNeeded(string $token, array $tokenInfo): void
    {
        $config = self::getConfig();
        $timeout = (int) $config['timeout'];

        // 先保证 loginIdKey 映射存在（统一用数组存储，无论并发模式还是非并发模式）
        $loginIdKey = null;
        $needsRebuild = false;
        $lockKey = null;
        $locked = false;
        if (isset($tokenInfo['loginId']) && is_int($tokenInfo['loginId'])) {
            $loginId = (int) $tokenInfo['loginId'];
            $loginIdKey = 'satoken:loginId:'.$loginId;
            $lockKey = 'login:'.$loginId;

            // Redis 模式下加锁，防止多个请求同时重建映射时的竞态条件
            $locked = self::acquireLock($lockKey, 3, 100);

            try {
                $mapping = Cache::get($loginIdKey);

                if (is_array($mapping)) {
                    $needsRebuild = ! in_array($token, $mapping, true);
                } elseif (is_string($mapping) && $mapping !== '') {
                    $needsRebuild = $mapping !== $token;
                } else {
                    $needsRebuild = true;
                }

                if ($needsRebuild) {
                    // 重建：先清理再把当前 token 加入
                    if (is_array($mapping)) {
                        $list = self::cleanTokenList($mapping);
                    } elseif (is_string($mapping) && $mapping !== '') {
                        $list = Cache::has("satoken:token:$mapping") ? [$mapping] : [];
                    } else {
                        $list = [];
                    }
                    if (! in_array($token, $list, true)) {
                        $list[] = $token;
                    }

                    // 强制限制登录数量，与 login() 逻辑保持一致：超过 max_login_count 则踢出最早 token
                    $maxCount = self::resolveMaxLoginCount($config);
                    while (count($list) > $maxCount) {
                        $oldestToken = array_shift($list);
                        if (is_string($oldestToken)) {
                            Cache::delete("satoken:token:$oldestToken");
                        }
                    }

                    // 使用列表中 token 的最小剩余时间作为 TTL
                    $ttl = self::getMinRemainingTime($list, $timeout);
                    Cache::set($loginIdKey, $list, $ttl);
                }
            } finally {
                if ($locked && self::isRedisDriver()) {
                    self::releaseLock($lockKey);
                }
            }
        }

        if (empty($config['auto_renew'])) {
            return;
        }

        $threshold = $config['renew_threshold'];
        if (! is_numeric($threshold) || $threshold <= 0 || $threshold > 1) {
            $threshold = 0.3;
        }

        $expireTime = isset($tokenInfo['expire_time']) ? (int) $tokenInfo['expire_time'] : 0;
        $remaining = $expireTime - time();

        // 只有剩余时间 < 阈值比例 * timeout 时才真正续期
        $needsRenew = $remaining < $timeout * $threshold;

        // 刷新 tokenKey 的 TTL
        $tokenKey = "satoken:token:$token";
        if ($needsRenew) {
            $tokenInfo['expire_time'] = time() + $timeout;
            Cache::set($tokenKey, $tokenInfo, $timeout);
        }

        // 同步刷新 loginIdKey 的 TTL：needsRebuild=true 时重建路径已写入，不再重复；否则用列表中 token 的最小剩余时间
        if ($loginIdKey !== null && $needsRenew && !$needsRebuild) {
            // 刷新 loginIdKey TTL 时也需要加锁，防止与其他并发写操作冲突
            $locked2 = self::acquireLock($lockKey, 3, 100);
            try {
                $mapping = Cache::get($loginIdKey);
                if (is_array($mapping)) {
                    $ttl = self::getMinRemainingTime($mapping, $timeout);
                    Cache::set($loginIdKey, $mapping, $ttl);
                } elseif (is_string($mapping) && $mapping !== '') {
                    $ttl = self::getMinRemainingTime([$mapping], $timeout);
                    Cache::set($loginIdKey, $mapping, $ttl);
                }
            } finally {
                if ($locked2 && self::isRedisDriver()) {
                    self::releaseLock($lockKey);
                }
            }
        }
    }

    /**
     * 检查是否已登录
     *
     * @param string|null $token 用户token
     * @return bool 是否已登录
     */
    public static function isLogin(?string $token = null): bool
    {
        $token = self::resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = self::fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        if (self::extractLoginId($tokenInfo) === null) {
            return false;
        }

        // 滑动续期（仅在需要时写缓存）
        self::renewIfNeeded($token, $tokenInfo);

        return true;
    }

    /**
     * 检查是否已登录，如果未登录或token无效则抛出异常
     *
     * 与 isLogin()/getCurrentLoginId() 的区别：
     * - isLogin()：静默返回 bool，可用于判断分支
     * - getCurrentLoginId()：返回 loginId，同时会触发滑动续期
     * - checkLogin()：纯校验，不返回 loginId，专门用于权限拦截；会触发滑动续期以保持与 isLogin 一致
     *
     * @param string|null $token 用户token；为null时自动从请求中获取
     *
     * @throws NotLoginException    未提供token
     * @throws TokenInvalidException token无效或已过期
     */
    public static function checkLogin(?string $token = null): void
    {
        if (empty($token)) {
            $token = self::getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = self::getValidTokenInfo($token);
        self::extractLoginIdOrThrow($tokenInfo);
        self::renewIfNeeded($token, $tokenInfo);
    }

    /**
     * 获取当前登录用户的loginId
     *
     * @param string|null $token 用户token
     * @return int 登录用户ID
     */
    public static function getCurrentLoginId(?string $token = null): int
    {
        if (empty($token)) {
            $token = self::getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = self::getValidTokenInfo($token);
        $loginId = self::extractLoginIdOrThrow($tokenInfo);
        self::renewIfNeeded($token, $tokenInfo);

        return $loginId;
    }

    /**
     * @param string|null $token
     * @return array<string, mixed>
     */
    public static function getTokenInfo(?string $token = null): array
    {
        if (empty($token)) {
            $token = self::getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = self::getValidTokenInfo($token);
        self::renewIfNeeded($token, $tokenInfo);

        return $tokenInfo;
    }

    /**
     * @param string|null $token
     * @return array<string, mixed>
     */
    public static function getExtra(?string $token = null): array
    {
        $info = self::getTokenInfo($token);

        if (! isset($info['extra']) || ! is_array($info['extra'])) {
            return [];
        }

        $result = [];
        foreach ($info['extra'] as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param string|null $token
     * @param array<string, mixed> $extra
     */
    public static function setExtra(?string $token = null, array $extra = []): bool
    {
        $token = self::resolveToken($token);
        if ($token === null) {
            return false;
        }

        // Redis 模式下按 token 粒度加锁，防止并发 setExtra 相互覆盖
        $lockKey = 'token:'.$token;
        $locked = self::acquireLock($lockKey, 3, 100);

        try {
            $tokenInfo = self::fetchTokenInfo($token);
            if ($tokenInfo === null) {
                return false;
            }

            $remain = 0;
            if (! empty($tokenInfo['expire_time'])) {
                $remain = (int) ((int) $tokenInfo['expire_time'] - time());
            }
            if ($remain <= 0) {
                return false;
            }
            $tokenInfo['extra'] = $extra;
            Cache::set("satoken:token:$token", $tokenInfo, $remain);
        } finally {
            if ($locked && self::isRedisDriver()) {
                self::releaseLock($lockKey);
            }
        }

        return true;
    }

    /**
     * 获取指定token的过期时间戳（秒）
     *
     * @param string|null $token 用户token
     * @return int 过期时间戳，为0表示不可用或未找到
     */
    public static function getTokenExpireTime(?string $token = null): int
    {
        $token = self::resolveToken($token);
        if ($token === null) {
            return 0;
        }

        $tokenInfo = self::fetchTokenInfo($token);
        if ($tokenInfo === null || empty($tokenInfo['expire_time'])) {
            return 0;
        }

        return (int) $tokenInfo['expire_time'];
    }

    /**
     * 获取指定token的剩余有效秒数
     *
     * @param string|null $token 用户token
     * @return int 剩余秒数，为0表示已过期或未找到
     */
    public static function getTokenRemainingTime(?string $token = null): int
    {
        $expire = self::getTokenExpireTime($token);
        $remain = $expire - time();

        return max($remain, 0);
    }

    /**
     * 强制踢出指定用户的所有 token（根据 loginId 踢出）
     *
     * @param int $id 用户登录ID
     * @return bool 是否踢出成功（至少有一个 token 被移除）
     */
    public static function kickout(int $id): bool
    {
        $loginIdKey = "satoken:loginId:$id";

        $raw = Cache::get($loginIdKey);
        if (is_array($raw)) {
            $tokenList = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $tokenList = [$raw];
        } else {
            $tokenList = [];
        }

        $removedCount = 0;
        foreach ($tokenList as $t) {
            if (! is_string($t) || $t === '') {
                continue;
            }
            Cache::delete("satoken:token:$t");
            $removedCount++;
        }

        Cache::delete($loginIdKey);

        return $removedCount > 0;
    }

    /**
     * 强制踢出指定 token
     *
     * @param string $token 用户token
     * @return bool 是否踢出成功
     */
    public static function kickoutByToken(string $token): bool
    {
        $token = self::resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = self::fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $loginId = self::extractLoginId($tokenInfo);
        if ($loginId === null) {
            return false;
        }

        return self::removeToken($token, $loginId);
    }
}