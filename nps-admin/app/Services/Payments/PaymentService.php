<?php

namespace App\Services\Payments;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\User;
use App\Services\Portal\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 支付订单生命周期：下单 → 渠道二维码 → 回调/模拟到账 → 幂等入账
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly BillingService $billing,
    ) {}

    public function create(User $user, int $amountCents, string $channel, string $subject = '账户充值', ?int $billId = null): Payment
    {
        if ($amountCents <= 0) {
            throw new PaymentException('amount must be positive');
        }
        $driver = $this->manager->driver($channel);

        $bill = null;
        if ($billId !== null) {
            $bill = Bill::where('user_id', $user->id)->find($billId);
            if (! $bill) {
                throw new PaymentException('bill not found');
            }
            if ($bill->status === 'paid') {
                throw new PaymentException('bill already paid');
            }
            if ((int) $bill->total_fee_cents !== $amountCents) {
                throw new PaymentException('payment amount does not match bill total');
            }
            $subject = "账单 {$bill->period}";
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'bill_id' => $bill?->id,
            'channel' => $channel,
            'out_trade_no' => $this->outTradeNo(),
            'subject' => $subject,
            'amount_cents' => $amountCents,
            'status' => Payment::STATUS_PENDING,
            'expires_at' => now()->addMinutes((int) config('payments.expire_minutes', 30)),
        ]);

        try {
            $result = $driver->create($payment);
            $payment->update([
                'qr_code' => $result['qr_code'] ?? null,
                'pay_url' => $result['pay_url'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $payment->update(['status' => Payment::STATUS_FAILED]);
            throw $e;
        }

        return $payment->fresh();
    }

    /**
     * 渠道回调入口：验签 → 定位订单 → 金额核对 → 幂等入账
     *
     * @return array{out_trade_no: string, paid: bool, ignored?: string}
     */
    public function handleNotify(string $channel, Request $request): array
    {
        $driver = $this->manager->driver($channel);
        $data = $driver->verify($request);

        $payment = Payment::where('out_trade_no', $data['out_trade_no'])->first();
        if (! $payment || $payment->channel !== $channel) {
            throw new PaymentException('payment order not found');
        }
        if ($payment->status === Payment::STATUS_PAID) {
            return ['out_trade_no' => $payment->out_trade_no, 'paid' => true, 'ignored' => 'already paid'];
        }
        if ($data['amount_cents'] !== $payment->amount_cents) {
            throw new PaymentException('payment amount mismatch');
        }
        if (! $data['paid']) {
            return ['out_trade_no' => $payment->out_trade_no, 'paid' => false, 'ignored' => 'not success state'];
        }

        $this->markPaid($payment, $data['transaction_id'], $data['payload']);

        return ['out_trade_no' => $payment->out_trade_no, 'paid' => true];
    }

    /**
     * 幂等入账：账单支付标记账单；余额充值入钱包
     */
    public function markPaid(Payment $payment, ?string $transactionId = null, array $payload = []): bool
    {
        return DB::transaction(function () use ($payment, $transactionId, $payload) {
            /** @var Payment $locked */
            $locked = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === Payment::STATUS_PAID) {
                return false;
            }

            $locked->update([
                'status' => Payment::STATUS_PAID,
                'transaction_id' => $transactionId,
                'notify_payload' => $payload ?: null,
                'paid_at' => now(),
            ]);

            if ($locked->bill_id !== null) {
                Bill::where('id', $locked->bill_id)->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            } else {
                $this->billing->recharge(
                    $locked->user,
                    $locked->amount_cents,
                    "payment {$locked->out_trade_no}",
                    "payment:{$locked->id}",
                );
            }

            return true;
        });
    }

    /**
     * 过期未支付订单回收（scheduler）
     */
    public function expirePending(): int
    {
        return Payment::where('status', Payment::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => Payment::STATUS_EXPIRED]);
    }

    private function outTradeNo(): string
    {
        do {
            $no = 'XN'.now()->format('YmdHis').strtoupper(Str::random(8));
        } while (Payment::where('out_trade_no', $no)->exists());

        return $no;
    }
}
