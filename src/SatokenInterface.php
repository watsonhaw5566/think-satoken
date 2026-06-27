<?php

namespace satoken;

interface SatokenInterface
{
    /**
     * 生成token
     *
     * @return string token
     */
    public static function createToken(): string;

    /**
     * 验证token格式是否正确
     *
     * @param  string  $token  要验证的token
     * @return bool 格式是否正确
     */
    public static function validateTokenFormat(string $token): bool;

    /**
     * 登录功能
     *
     * @param  int  $loginId  用户登录ID
     * @param  array<string, mixed>  $extra  额外自定义内容
     * @return string 生成的token
     */
    public static function login(int $loginId, array $extra = []): string;

    /**
     * 登出功能
     *
     * @param  string|null  $token  用户token
     * @return bool 是否登出成功
     */
    public static function logout(?string $token = null): bool;

    /**
     * 强制踢出指定用户的所有 token（根据 loginId 踢出）
     *
     * @param  int  $id  用户登录ID
     * @return bool 是否踢出成功（至少有一个 token 被移除）
     */
    public static function kickout(int $id): bool;

    /**
     * 强制踢出指定 token
     *
     * @param  string  $token  用户token
     * @return bool 是否踢出成功
     */
    public static function kickoutByToken(string $token): bool;

    /**
     * 检查是否已登录
     *
     * @param  string|null  $token  用户token
     * @return bool 是否已登录
     */
    public static function isLogin(?string $token = null): bool;

    /**
     * 检查是否已登录，如果未登录或token无效则抛出异常
     *
     * 与 isLogin() 的区别：
     * - isLogin() 仅返回 bool，不会抛出异常
     * - checkLogin() 在未登录/无效token时抛出对应异常，适合作为权限拦截器使用
     *
     * @param  string|null  $token  用户token；为null时自动从请求中获取
     * @return void
     *
     * @throws \satoken\exception\NotLoginException    未提供token
     * @throws \satoken\exception\TokenInvalidException token无效或已过期
     */
    public static function checkLogin(?string $token = null): void;

    /**
     * 获取当前登录用户的loginId
     *
     * @param  string|null  $token  用户token
     * @return int 登录用户ID
     */
    public static function getCurrentLoginId(?string $token = null): int;

    /**
     * 获取指定token的过期时间戳（秒）
     *
     * @param  string|null  $token  用户token
     * @return int 过期时间戳，为0表示不可用或未找到
     */
    public static function getTokenExpireTime(?string $token = null): int;

    /**
     * 获取指定token的剩余有效秒数
     *
     * @param  string|null  $token  用户token
     * @return int 剩余秒数，为0表示已过期或未找到
     */
    public static function getTokenRemainingTime(?string $token = null): int;

    /**
     * @param  string|null  $token
     * @return array<string, mixed>
     */
    public static function getTokenInfo(?string $token = null): array;

    /**
     * @param  string|null  $token
     * @return array<string, mixed>
     */
    public static function getExtra(?string $token = null): array;

    /**
     * @param  string|null  $token
     * @param  array<string, mixed>  $extra
     */
    public static function setExtra(?string $token = null, array $extra = []): bool;
}