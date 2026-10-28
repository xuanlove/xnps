<?php

namespace App\Services\Payments;

/**
 * 密钥加载：支持 PEM 内容（环境变量中 \n 转义）或文件路径
 */
class PaymentKeys
{
    public static function load(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_contains($value, 'BEGIN ')) {
            return str_replace('\n', "\n", $value);
        }
        if (is_file($value) && is_readable($value)) {
            return (string) file_get_contents($value);
        }

        return str_replace('\n', "\n", $value);
    }

    public static function orFail(string $value, string $name): string
    {
        $pem = self::load($value);
        if ($pem === '') {
            throw new PaymentException("payment key not configured: {$name}");
        }

        return $pem;
    }
}
