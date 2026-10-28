<?php

namespace App\Services\Payments\Drivers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentDriver;
use App\Services\Payments\PaymentException;
use Illuminate\Http\Request;

/**
 * 本地 mock 渠道：仅开发/联调，二维码为伪协议，通过 /portal/payments/{id}/mock-pay 模拟到账
 */
class MockDriver implements PaymentDriver
{
    public function channel(): string
    {
        return Payment::CHANNEL_MOCK;
    }

    public function create(Payment $payment): array
    {
        if (! config('payments.mock_enabled')) {
            throw new PaymentException('mock channel is disabled');
        }

        return [
            'qr_code' => 'mock://pay/'.$payment->out_trade_no,
            'pay_url' => null,
        ];
    }

    public function verify(Request $request): array
    {
        throw new PaymentException('mock channel has no real notify');
    }
}
