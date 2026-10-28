<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentDriver
{
    public function channel(): string;

    /**
     * 创建支付单，返回 ['qr_code' => ?string, 'pay_url' => ?string]
     *
     * @return array{qr_code: ?string, pay_url: ?string}
     */
    public function create(Payment $payment): array;

    /**
     * 校验并规范化渠道回调。
     *
     * @return array{out_trade_no: string, transaction_id: ?string, amount_cents: int, paid: bool, payload: array}
     */
    public function verify(Request $request): array;
}
