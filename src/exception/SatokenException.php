<?php

namespace satoken\exception;

use RuntimeException;
use Throwable;

/**
 * SaToken 基础异常类
 */
class SatokenException extends RuntimeException
{
    /**
     * 构造函数
     *
     * @param  string  $message  错误消息
     * @param  int  $code  错误码
     * @param  Throwable|null  $previous  上一个异常
     */
    public function __construct(string $message = 'SaToken Exception', int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取错误码
     *
     * @return int 错误码
     */
    public function getErrorCode(): int
    {
        return (int) $this->getCode();
    }
}
