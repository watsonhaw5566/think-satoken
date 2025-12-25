<?php

namespace satoken\exception;

/**
 * SaToken 基础异常类
 */
class SatokenException extends \RuntimeException
{
    // 错误码
    protected int $errorCode;

    /**
     * 构造函数
     *
     * @param  string  $message  错误消息
     * @param  int  $code  错误码
     * @param  \Throwable|null  $previous  上一个异常
     */
    public function __construct(string $message = 'SaToken Exception', int $code = 400, ?\Throwable $previous = null)
    {
        $this->errorCode = $code;
        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取错误码
     *
     * @return int 错误码
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}
