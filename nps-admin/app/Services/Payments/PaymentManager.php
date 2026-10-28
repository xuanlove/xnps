<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentDriver;
use App\Services\Payments\Drivers\AlipayDriver;
use App\Services\Payments\Drivers\MockDriver;
use App\Services\Payments\Drivers\WechatDriver;

/**
 * 支付渠道路由：按 payment.channel 选择驱动，并按配置裁剪可用渠道
 */
class PaymentManager
{
    public function __construct(
        private readonly AlipayDriver $alipay,
        private readonly WechatDriver $wechat,
        private readonly MockDriver $mock,
    ) {}

    public function driver(string $channel): PaymentDriver
    {
        $driver = match ($channel) {
            Payment::CHANNEL_ALIPAY => $this->alipay,
            Payment::CHANNEL_WECHAT => $this->wechat,
            Payment::CHANNEL_MOCK => $this->mock,
            default => throw new PaymentException("unsupported payment channel: {$channel}"),
        };

        if (! $this->enabled($channel)) {
            throw new PaymentException("payment channel is not enabled: {$channel}");
        }

        return $driver;
    }

    /**
     * @return array<int, string>
     */
    public function enabledChannels(): array
    {
        return array_values(array_filter([
            Payment::CHANNEL_ALIPAY,
            Payment::CHANNEL_WECHAT,
            Payment::CHANNEL_MOCK,
        ], fn (string $channel) => $this->enabled($channel)));
    }

    public function enabled(string $channel): bool
    {
        return match ($channel) {
            Payment::CHANNEL_ALIPAY => (bool) config('payments.alipay.enabled'),
            Payment::CHANNEL_WECHAT => (bool) config('payments.wechat.enabled'),
            Payment::CHANNEL_MOCK => (bool) config('payments.mock_enabled'),
            default => false,
        };
    }
}
