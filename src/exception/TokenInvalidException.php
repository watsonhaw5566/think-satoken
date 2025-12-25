<?php

namespace satoken\exception;

/**
 * Token 无效异常
 */
class TokenInvalidException extends SatokenException
{
    public function __construct(string $message = 'Token无效', int $code = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
