<?php

namespace App\Services\Provisioning;

use RuntimeException;

/**
 * 开通/补偿失败异常
 */
class ProvisioningException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
