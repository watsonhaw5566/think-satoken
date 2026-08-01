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
 * Think-SaToken - 轻量级权限认证
 *
 * 设计原则：简单、直观，适合中小型项目
 * 默认单端登录（新设备登录自动顶掉旧设备）
 *
 * 推荐通过 {@see \satoken\facade\SaToken} 门面以静态方式调用（契合 ThinkPHP 习惯）。
 */
class SaToken implements SatokenInterface
{
    /**
     * 默认配置存储
     *
     * @var array<string, mixed>
     */
    protected $config = [
        'token_name' => '',       // 自定义 Token 请求头名称
        'timeout' => 604800,      // Token 有效期（秒），默认 7 天
        'auto_renew' => true,     // 是否启用滑动续期
        'renew_buffer' => 3600,   // 续期缓冲时间（秒），剩余不足此时才续期，默认 1 小时
    ];

    /**
     * 缓存当前驱动是否为 Redis
     *
     * @var bool|null
     */
    protected $isRedisDriver = null;

    /**
     * 检测当前缓存驱动是否为 Redis
     *
     * @return bool
     */
    public function isRedisDriver(): bool
    {
        if ($this->isRedisDriver !== null) {
            return $this->isRedisDriver;
        }

        try {
            $driver = Cache::store();
            $isRedis = $driver instanceof RedisDriver;

            if (!$isRedis && is_object($driver)) {
                $className = get_class($driver);
                $isRedis = stripos($className, 'redis') !== false;
            }

            $this->isRedisDriver = $isRedis;

            return $isRedis;
        } catch (\Throwable $e) {
            $this->isRedisDriver = false;

            return false;
        }
    }

    /**
     * 重置驱动检测状态（主要用于测试或驱动切换场景）
     */
    public function resetDriverDetection(): void
    {
        $this->isRedisDriver = null;
    }

    /**
     * 登录功能（单端登录：新登录自动顶掉旧 token）
     *
     * @param int $loginId 用户登录ID
     * @param array<string, mixed> $extra 额外自定义内容
     * @return string 生成的token
     */
    public function login(int $loginId, array $extra = []): string
    {
        $config = $this->getConfig();
        $timeout = (int) $config['timeout'];

        $token = $this->createToken();
        $tokenKey = "satoken:token:$token";
        $loginIdKey = "satoken:loginId:$loginId";

        // 顶掉旧 token
        $oldToken = Cache::get($loginIdKey);
        if (is_string($oldToken) && $oldToken !== '') {
            Cache::delete("satoken:token:$oldToken");
        }

        // 存储 token 信息
        $tokenInfo = [
            'loginId' => $loginId,
            'create_time' => time(),
            'expire_time' => time() + $timeout,
            'extra' => $extra,
        ];
        Cache::set($tokenKey, $tokenInfo, $timeout);

        // 更新 loginId -> token 映射（单 token，直接覆盖）
        Cache::set($loginIdKey, $token, $timeout);

        return $token;
    }

    public function createToken(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        $satokenConfig = Config::get('satoken');
        if (! is_array($satokenConfig)) {
            $satokenConfig = [];
        }

        $merged = [];
        foreach (array_merge($this->config, $satokenConfig) as $key => $value) {
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
    private function resolveToken(?string $token): ?string
    {
        if (empty($token)) {
            $token = $this->getToken();
        }

        return empty($token) ? null : $token;
    }

    /**
     * 验证 token 格式并从缓存获取 tokenInfo（不触发续期）
     *
     * @param  string  $token  已解析的 token
     * @return array<string, mixed>|null 格式有效且缓存存在时返回 tokenInfo，否则返回 null
     */
    private function fetchTokenInfo(string $token): ?array
    {
        if (! $this->validateTokenFormat($token)) {
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
    private function extractLoginId(array $tokenInfo): ?int
    {
        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            return null;
        }

        return $tokenInfo['loginId'];
    }

    /**
     * 验证 token 并返回完整的 tokenInfo，失败时抛出对应异常
     *
     * @param  string  $token  已解析且非空的 token
     * @return array<string, mixed> 有效的 tokenInfo
     *
     * @throws TokenInvalidException 格式无效或缓存中不存在
     */
    private function getValidTokenInfo(string $token): array
    {
        if (! $this->validateTokenFormat($token)) {
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
    private function extractLoginIdOrThrow(array $tokenInfo): int
    {
        $loginId = $this->extractLoginId($tokenInfo);
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
    public function logout(?string $token = null): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $loginId = $this->extractLoginId($tokenInfo);
        if ($loginId === null) {
            return false;
        }

        // 删除 token 信息和映射
        $tokenKey = "satoken:token:$token";
        $loginIdKey = "satoken:loginId:$loginId";

        Cache::delete($tokenKey);
        Cache::delete($loginIdKey);

        return true;
    }

    private function getToken(): ?string
    {
        $config = $this->getConfig();
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

    public function validateTokenFormat(string $token): bool
    {
        if (strlen($token) !== 36) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token);
    }

    /**
     * 滑动续期：如果开启 auto_renew 且剩余时间低于缓冲值，则刷新 TTL
     *
     * @param string $token 已验证格式的 token
     * @param array<string, mixed> $tokenInfo 当前 token 信息
     */
    private function renewIfNeeded(string $token, array $tokenInfo): void
    {
        $config = $this->getConfig();
        if (empty($config['auto_renew'])) {
            return;
        }

        $timeout = (int) $config['timeout'];
        $buffer = isset($config['renew_buffer']) ? (int) $config['renew_buffer'] : 3600;
        if ($buffer < 0) {
            $buffer = 3600;
        }

        $expireTime = isset($tokenInfo['expire_time']) ? (int) $tokenInfo['expire_time'] : 0;
        $remaining = $expireTime - time();

        // 剩余时间不足缓冲值时才续期
        if ($remaining >= $buffer) {
            return;
        }

        $newExpire = time() + $timeout;
        $tokenInfo['expire_time'] = $newExpire;

        $tokenKey = "satoken:token:$token";
        Cache::set($tokenKey, $tokenInfo, $timeout);

        // 同步续期 loginId -> token 映射
        if (isset($tokenInfo['loginId']) && is_int($tokenInfo['loginId'])) {
            $loginIdKey = 'satoken:loginId:'.$tokenInfo['loginId'];
            Cache::set($loginIdKey, $token, $timeout);
        }
    }

    /**
     * 检查是否已登录
     *
     * @param string|null $token 用户token
     * @return bool 是否已登录
     */
    public function isLogin(?string $token = null): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        if ($this->extractLoginId($tokenInfo) === null) {
            return false;
        }

        $this->renewIfNeeded($token, $tokenInfo);

        return true;
    }

    /**
     * 检查是否已登录，如果未登录或token无效则抛出异常
     *
     * @param string|null $token 用户token；为null时自动从请求中获取
     *
     * @throws NotLoginException    未提供token
     * @throws TokenInvalidException token无效或已过期
     */
    public function checkLogin(?string $token = null): void
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $this->extractLoginIdOrThrow($tokenInfo);
        $this->renewIfNeeded($token, $tokenInfo);
    }

    /**
     * 获取当前登录用户的loginId
     *
     * @param string|null $token 用户token
     * @return int 登录用户ID
     */
    public function getCurrentLoginId(?string $token = null): int
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $loginId = $this->extractLoginIdOrThrow($tokenInfo);
        $this->renewIfNeeded($token, $tokenInfo);

        return $loginId;
    }

    /**
     * @param string|null $token
     * @return array<string, mixed>
     */
    public function getTokenInfo(?string $token = null): array
    {
        if (empty($token)) {
            $token = $this->getToken();
            if (empty($token)) {
                throw new NotLoginException('未提供token');
            }
        }

        $tokenInfo = $this->getValidTokenInfo($token);
        $this->renewIfNeeded($token, $tokenInfo);

        return $tokenInfo;
    }

    /**
     * @param string|null $token
     * @return array<string, mixed>
     */
    public function getExtra(?string $token = null): array
    {
        $info = $this->getTokenInfo($token);

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
    public function setExtra(?string $token = null, array $extra = []): bool
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return false;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
        if ($tokenInfo === null) {
            return false;
        }

        $remain = 0;
        if (! empty($tokenInfo['expire_time'])) {
            $remain = (int) $tokenInfo['expire_time'] - time();
        }
        if ($remain <= 0) {
            return false;
        }
        $tokenInfo['extra'] = $extra;
        Cache::set("satoken:token:$token", $tokenInfo, $remain);

        return true;
    }

    /**
     * 获取指定token的过期时间戳（秒）
     *
     * @param string|null $token 用户token
     * @return int 过期时间戳，为0表示不可用或未找到
     */
    public function getTokenExpireTime(?string $token = null): int
    {
        $token = $this->resolveToken($token);
        if ($token === null) {
            return 0;
        }

        $tokenInfo = $this->fetchTokenInfo($token);
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
    public function getTokenRemainingTime(?string $token = null): int
    {
        $expire = $this->getTokenExpireTime($token);
        $remain = $expire - time();

        return max($remain, 0);
    }

    /**
     * 强制踢出指定用户（顶号：删除其当前 token）
     *
     * @param int $id 用户登录ID
     * @return bool 是否踢出成功
     */
    public function kickout(int $id): bool
    {
        $loginIdKey = "satoken:loginId:$id";
        $token = Cache::get($loginIdKey);

        if (!is_string($token) || $token === '') {
            return false;
        }

        Cache::delete("satoken:token:$token");
        Cache::delete($loginIdKey);

        return true;
    }

    /**
     * 强制踢出指定 token
     *
     * @param string $token 用户token
     * @return bool 是否踢出成功
     */
    public function kickoutByToken(string $token): bool
    {
        return $this->logout($token);
    }
}
