<?php

namespace satoken\exception;

use Throwable;

/**
 * 未登录异常
 */
class NotLoginException extends SatokenException
{
    public function __construct(string $message = '未登录', int $code = 401, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
