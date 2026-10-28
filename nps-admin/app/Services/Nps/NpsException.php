<?php

namespace App\Services\Nps;

use RuntimeException;

/**
 * nps API 调用异常（网络失败 / 鉴权失败 / 业务失败）
 */
class NpsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
