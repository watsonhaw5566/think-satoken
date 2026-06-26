<?php

namespace satoken\middleware;

use Closure;
use satoken\exception\NotLoginException;
use satoken\exception\SatokenException;
use satoken\exception\TokenInvalidException;
use satoken\SaToken;

class SatokenAuth
{
    /**
     * @param  mixed  $request
     * @param  Closure(mixed): mixed  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            // 检查登录状态
            SaToken::checkLogin();
        } catch (NotLoginException|TokenInvalidException $e) {
            return json([
                'code' => $e->getErrorCode(),
                'msg' => $e->getMessage(),
                'data' => null,
            ], 401);
        } catch (SatokenException $e) {
            return json([
                'code' => $e->getErrorCode(),
                'msg' => $e->getMessage(),
                'data' => null,
            ], 400);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '服务器内部错误',
                'data' => null,
            ], 500);
        }

        return $next($request);
    }
}