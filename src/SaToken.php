<?php

namespace satoken;

use Ramsey\Uuid\Uuid;
use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;
use think\facade\Cache;
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
        'is_concurrent' => true, // 是否允许同一账号多地登录
        'max_login_count' => 10, // 同一账号最大登录数量
        'auto_renew' => true, // 是否启用滑动续期
    ];

    /**
     * 登录功能
     * 将loginId融入token中，并处理并发登录
     *
     * @param int $loginId 用户登录ID
     * @param array<string, mixed> $extra 额外自定义内容
     * @return string 生成的token
     */
    public static function login(int $loginId, array $extra = []): string
    {
        // 创建token
        $token = self::createToken();

        // 生成带loginId信息的token键
        $tokenKey = "satoken:token:$token";
        $loginIdKey = "satoken:loginId:$loginId";

        // 检查是否允许并发登录
        $config = self::getConfig();

        if (empty($config['is_concurrent'])) {
            // 不允许并发登录，先清除该用户的所有登录信息（兼容历史并发映射）
            $old = Cache::get($loginIdKey);
            if (is_array($old)) {
                foreach ($old as $t) {
                    if (is_string($t)) {
                        Cache::delete("satoken:token:$t");
                    }
                }
            } elseif (is_string($old) && $old !== '') {
                Cache::delete("satoken:token:$old");
            }

            // 存储新的token与loginId映射
            Cache::set($loginIdKey, $token, (int) $config['timeout']);
        } else {
            // 允许并发登录，检查最大登录数量
            $tokenList = Cache::get($loginIdKey, []);
            if (!is_array($tokenList)) {
                $tokenList = [];
            }

            // 添加新token到列表
            $tokenList[] = $token;

            // 如果超过最大登录数量，移除最早的token
            if (count($tokenList) > (int) $config['max_login_count']) {
                $oldestToken = array_shift($tokenList);
                if (is_string($oldestToken)) {
                    Cache::delete("satoken:token:$oldestToken");
                }
            }

            // 存储token列表
            Cache::set($loginIdKey, $tokenList, (int) $config['timeout']);
        }

        // 存储token信息，包含loginId与自定义内容
        $tokenInfo = [
            'loginId' => $loginId,
            'create_time' => time(),
            'expire_time' => time() + (int) $config['timeout'],
            'extra' => $extra,
        ];

        Cache::set($tokenKey, $tokenInfo, (int) $config['timeout']);

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
        $satokenConfig = config('satoken');
        if (! is_array($satokenConfig)) {
            $satokenConfig = [];
        }

        return array_merge(self::$config, $satokenConfig);
    }

    /**
     * 登出功能
     *
     * @param string|null $token 用户token
     * @return bool 是否登出成功
     */
    public static function logout(?string $token = null): bool
    {
        $config = self::getConfig();
        if (empty($token)) {
            // 从请求中获取token
            $token = self::getToken();
            if (empty($token)) {
                return false;
            }
        }

        // 添加token格式验证
        if (!self::validateTokenFormat($token)) {
            return false;
        }

        // 获取token信息
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            return false;
        }

        // 从loginId对应的token列表中移除该token
        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            return false;
        }
        $loginId = $tokenInfo['loginId'];
        $loginIdKey = "satoken:loginId:$loginId";

        if (! empty($config['is_concurrent'])) {
            $tokenList = Cache::get($loginIdKey, []);
            if (is_array($tokenList)) {
                $tokenList = array_values(array_filter($tokenList, static function ($t) use ($token): bool {
                    return is_string($t) && $t !== $token;
                }));

                if (empty($tokenList)) {
                    Cache::delete($loginIdKey);
                } else {
                    Cache::set($loginIdKey, $tokenList, (int) $config['timeout']);
                }
            }
        } else {
            Cache::delete($loginIdKey);
        }

        // 删除token信息
        Cache::delete($tokenKey);

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
        // 严格验证 UUID v4 格式（第三段以 4 开头，第四段变体为 8|9|a|b）
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token);
    }

    /**
     * 检查是否已登录
     *
     * @param string|null $token 用户token
     * @return bool 是否已登录
     */
    public static function isLogin(?string $token = null): bool
    {
        if (empty($token)) {
            // 从请求中获取token
            $token = self::getToken();
            if (empty($token)) {
                return false;
            }
        }

        // 添加token格式验证
        if (!self::validateTokenFormat($token)) {
            return false;
        }

        $config = self::getConfig();
        // 检查token是否存在且有效
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            return false;
        }

        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            return false;
        }

        if (! empty($config['auto_renew'])) {
            $tokenInfo['expire_time'] = time() + (int) $config['timeout'];
            Cache::set($tokenKey, $tokenInfo, (int) $config['timeout']);
        }

        // 同步续期 loginId 映射，避免映射早于 token 过期
        $loginIdKey = 'satoken:loginId:'.$tokenInfo['loginId'];
        $mapping = Cache::get($loginIdKey);
        if (! empty($config['is_concurrent'])) {
            if (is_array($mapping)) {
                Cache::set($loginIdKey, $mapping, (int) $config['timeout']);
            }
        } else {
            if (! empty($mapping)) {
                Cache::set($loginIdKey, $mapping, (int) $config['timeout']);
            } else {
                Cache::set($loginIdKey, $token, (int) $config['timeout']);
            }
        }

        return true;
    }

    /**
     * 检查是否已登录，如果未登录则抛出异常
     *
     * @param string|null $token 用户token
     */
    public static function checkLogin(?string $token = null): void
    {
        self::getCurrentLoginId($token);
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

        // 添加token格式验证
        if (!self::validateTokenFormat($token)) {
            throw new TokenInvalidException('无效的token格式');
        }

        $config = self::getConfig();
        // 获取token信息
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            throw new TokenInvalidException('无效的token');
        }

        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            throw new TokenInvalidException('token信息不完整');
        }

        return (int) $tokenInfo['loginId'];
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

        if (!self::validateTokenFormat($token)) {
            throw new TokenInvalidException('无效的token格式');
        }

        $config = self::getConfig();
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            throw new TokenInvalidException('无效的token');
        }

        return $tokenInfo;
    }

    /**
     * @param string|null $token
     * @return array<string, mixed>
     */
    public static function getExtra(?string $token = null): array
    {
        $info = self::getTokenInfo($token);

        return isset($info['extra']) && is_array($info['extra']) ? $info['extra'] : [];
    }

    /**
     * @param string|null $token
     * @param array<string, mixed> $extra
     */
    public static function setExtra(?string $token = null, array $extra = []): bool
    {
        if (empty($token)) {
            $token = self::getToken();
            if (empty($token)) {
                return false;
            }
        }

        if (!self::validateTokenFormat($token)) {
            return false;
        }
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
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
        Cache::set($tokenKey, $tokenInfo, $remain);

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
        if (empty($token)) {
            $token = self::getToken();
            if (empty($token)) {
                return 0;
            }
        }

        if (!self::validateTokenFormat($token)) {
            return 0;
        }

        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo) || empty($tokenInfo['expire_time'])) {
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
     * 强制踢出指定token用户
     *
     * @param string|null $token 用户token
     * @return bool 是否踢出成功
     */
    public static function kickout(?string $token = null): bool
    {
        if (empty($token)) {
            // 从请求中获取token
            $token = self::getToken();
            if (empty($token)) {
                return false;
            }
        }

        // 添加token格式验证
        if (!self::validateTokenFormat($token)) {
            return false;
        }

        // 获取token信息
        $config = self::getConfig();
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        if (! is_array($tokenInfo)) {
            return false;
        }

        if (! isset($tokenInfo['loginId']) || ! is_int($tokenInfo['loginId'])) {
            return false;
        }

        // 从loginId对应的token列表中移除该token
        $loginId = $tokenInfo['loginId'];
        $loginIdKey = "satoken:loginId:$loginId";

        if (! empty($config['is_concurrent'])) {
            $tokenList = Cache::get($loginIdKey, []);
            if (is_array($tokenList)) {
                $tokenList = array_values(array_filter($tokenList, static function ($t) use ($token): bool {
                    return is_string($t) && $t !== $token;
                }));

                if (empty($tokenList)) {
                    Cache::delete($loginIdKey);
                } else {
                    Cache::set($loginIdKey, $tokenList, (int) $config['timeout']);
                }
            }
        } else {
            // 非并发模式下，直接删除loginId对应的token映射
            Cache::delete($loginIdKey);
        }

        // 删除token信息
        Cache::delete($tokenKey);

        return true;
    }
}