<?php

namespace satoken\facade;

use satoken\SatokenInterface;
use think\Facade;

/**
 * SaToken 门面（Facade）
 *
 * 推荐通过此门面以静态方式调用 SaToken 功能，契合 ThinkPHP 使用习惯。
 * 底层通过容器解析获取 {@see \satoken\SatokenInterface} 的实现实例。
 *
 * @method static string  login(int $loginId, array $extra = [])                        登录并生成 token（旧 token 自动失效）
 * @method static bool    logout(?string $token = null)                                   登出指定 token（为空时自动获取请求 token）
 * @method static bool    kickout(int $id)                                                强制踢出指定用户（删除其当前 token）
 * @method static bool    kickoutByToken(string $token)                                   强制踢出指定 token
 * @method static bool    isLogin(?string $token = null)                                  检查是否已登录（静默，不抛异常）
 * @method static void    checkLogin(?string $token = null)                               检查是否已登录（未登录/无效 token 时抛出异常）
 * @method static int     getCurrentLoginId(?string $token = null)                        获取当前登录用户 loginId
 * @method static int     getTokenExpireTime(?string $token = null)                       获取指定 token 的过期时间戳
 * @method static int     getTokenRemainingTime(?string $token = null)                    获取指定 token 的剩余有效秒数
 * @method static array   getTokenInfo(?string $token = null)                             获取 token 完整信息
 * @method static array   getExtra(?string $token = null)                                 获取 token 附带的自定义信息
 * @method static bool    setExtra(?string $token = null, array $extra = [])              设置 token 附带的自定义信息
 * @method static string  createToken()                                                   生成 token
 * @method static bool    validateTokenFormat(string $token)                              验证 token 格式是否正确
 */
class SaToken extends Facade
{
    /**
     * 从容器中获取 SaToken 实现（通过接口绑定）
     *
     * @return string
     */
    protected static function getFacadeClass(): string
    {
        return SatokenInterface::class;
    }
}
