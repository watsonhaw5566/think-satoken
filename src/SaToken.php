<?php

namespace satoken;

use Ramsey\Uuid\Uuid;
use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Request;

/**
 * Think-SaToken - 轻量级权限认证
 *
 * 设计原则：简单、直观，适合中小型项目
 * 默认多 Token 在线：同一账号可同时持有多个 token（支持多端登录），超出 max_login_count 时自动顶掉最早的
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
        'token_name'      => '',       // 自定义 Token 请求头名称
        'store'           => null,          // 缓存通道名称（null 表示使用默认缓存）
        'timeout'         => 604800,      // Token 有效期（秒），默认 7 天
        'auto_renew'      => true,     // 是否启用滑动续期
        'renew_before'    => 3600,   // 在过期前多少秒续期（秒），剩余不足此时才续期，默认 1 小时
        'max_login_count' => 10,     // 同一账号最多同时在线的 token 数（即最大登录数），默认 10
    ];

    /**
     * 获取缓存实例（支持指定 store）
     *
     * @return \think\Cache
     */
    protected function cache()
    {
        $config = $this->getConfig();
        $store  = $config['store'] ?? null;

        return $store ? Cache::store($store) : Cache::store();
    }

    /**
     * 登录功能（多 Token 在线：同一账号可重复登录，最多 max_login_count 个，超出则顶掉最早的）
     *
     * @param int $loginId 用户登录ID
     * @param array<string, mixed> $extra 额外自定义内容
     * @return string 生成的token
     */
    public function login(int $loginId, array $extra = []): string
    {
        $config        = $this->getConfig();
        $timeout       = (int) $config['timeout'];
        $maxLoginCount = isset($config['max_login_count']) ? (int) $config['max_login_count'] : 10;
        if ($maxLoginCount < 1) {
            $maxLoginCount = 1;
        }
        $cache = $this->cache();

        $token      = $this->createToken();
        $tokenKey   = "satoken:token:$token";
        $loginIdKey = "satoken:loginId:$loginId";

        // 读取当前 loginId 绑定的 token 列表（按登录时间从早到晚排序）
        $tokenList = $cache->get($loginIdKey);
        if (! is_array($tokenList)) {
            $tokenList = [];
        }
        $tokenList = array_values(array_filter($tokenList, 'is_string'));

        // 先清理列表中已经失效（缓存不存在）的脏引用，避免误算数量
        $tokenList = array_values(array_filter($tokenList, function ($t) use ($cache) {
            return is_string($t) && $t !== '' && is_array($cache->get("satoken:token:$t"));
        }));

        // 超出最大登录数：顶掉最早的（数组头部）
        while (count($tokenList) >= $maxLoginCount) {
            $oldToken = array_shift($tokenList);
            if (is_string($oldToken) && $oldToken !== '') {
                $cache->delete("satoken:token:$oldToken");
            }
        }

        // 存储新 token 信息
        $tokenInfo = [
            'loginId'     => $loginId,
            'create_time' => time(),
            'expire_time' => time() + $timeout,
            'extra'       => $extra,
        ];
        $cache->set($tokenKey, $tokenInfo, $timeout);

        // 追加到 loginId -> token 列表尾部，并刷新列表 TTL
        $tokenList[] = $token;
        $cache->set($loginIdKey, $tokenList, $timeout);

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

        $tokenKey  = "satoken:token:$token";
        $tokenInfo = $this->cache()->get($tokenKey);

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

        $tokenKey  = "satoken:token:$token";
        $tokenInfo = $this->cache()->get($tokenKey);
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
     * 从 loginId -> token 列表中移除指定 token（用于 logout / kickoutByToken 后保持列表干净）
     *
     * @param int $loginId 用户ID
     * @param string $token 要移除的 token
     */
    private function removeTokenFromLoginIdList(int $loginId, string $token): void
    {
        $cache      = $this->cache();
        $loginIdKey = "satoken:loginId:$loginId";
        $tokenList  = $cache->get($loginIdKey);

        if (! is_array($tokenList)) {
            return;
        }

        $tokenList = array_values(array_filter($tokenList, function ($t) use ($token) {
            return is_string($t) && $t !== '' && $t !== $token;
        }));

        if (count($tokenList) > 0) {
            // 保持原有剩余 TTL 的思路：直接用 timeout 重写（列表 TTL 只取决于最晚活跃，保守起见重置为 timeout）
            $cache->set($loginIdKey, $tokenList, (int) $this->getConfig()['timeout']);
        } else {
            // 列表空了直接删，不留下空壳 key
            $cache->delete($loginIdKey);
        }
    }

    /**
     * 登出功能（仅登出指定的 token，同账号的其他 token 不受影响）
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

        $cache    = $this->cache();
        $tokenKey = "satoken:token:$token";
        $cache->delete($tokenKey);

        // 同步把此 token 从 loginId -> token 列表中移除，避免脏引用误占名额
        $this->removeTokenFromLoginIdList($loginId, $token);

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
     * 滑动续期：如果开启 auto_renew 且剩余时间低于阈值，则刷新当前 token 的 TTL
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

        $timeout     = (int) $config['timeout'];
        $renewBefore = isset($config['renew_before']) ? (int) $config['renew_before'] : 3600;
        if ($renewBefore < 0) {
            $renewBefore = 3600;
        }

        $expireTime = isset($tokenInfo['expire_time']) ? (int) $tokenInfo['expire_time'] : 0;
        $remaining  = $expireTime - time();

        // 剩余时间不足阈值时才续期
        if ($remaining >= $renewBefore) {
            return;
        }

        $newExpire                = time() + $timeout;
        $tokenInfo['expire_time'] = $newExpire;

        $cache    = $this->cache();
        $tokenKey = "satoken:token:$token";
        $cache->set($tokenKey, $tokenInfo, $timeout);
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
        $loginId   = $this->extractLoginIdOrThrow($tokenInfo);
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

        $cache     = $this->cache();
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
        $cache->set("satoken:token:$token", $tokenInfo, $remain);

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
     * 强制踢出指定用户（删除该账号下的全部 token，使其所有端都下线）
     *
     * @param int $id 用户登录ID
     * @return bool 是否踢出成功（至少有一个 token 被删除视为成功）
     */
    public function kickout(int $id): bool
    {
        $cache      = $this->cache();
        $loginIdKey = "satoken:loginId:$id";
        $tokenList  = $cache->get($loginIdKey);

        $deletedAny = false;
        if (is_array($tokenList)) {
            foreach ($tokenList as $oldToken) {
                if (is_string($oldToken) && $oldToken !== '') {
                    if ($cache->delete("satoken:token:$oldToken")) {
                        $deletedAny = true;
                    }
                }
            }
        }
        // 兼容旧格式：loginId -> 单 token 字符串
        elseif (is_string($tokenList) && $tokenList !== '') {
            $cache->delete("satoken:token:$tokenList");
            $deletedAny = true;
        }

        $cache->delete($loginIdKey);

        return $deletedAny;
    }

    /**
     * 强制踢出指定 token（仅使其单个 token 失效，同账号其他 token 不受影响）
     *
     * @param string $token 用户token
     * @return bool 是否踢出成功
     */
    public function kickoutByToken(string $token): bool
    {
        return $this->logout($token);
    }
}
