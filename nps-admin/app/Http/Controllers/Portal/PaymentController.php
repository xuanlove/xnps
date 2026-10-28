<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 门户支付：创建充值/账单支付订单、查询订单、mock 到账（仅开发）
 */
class PaymentController extends Controller
{
    public function channels(PaymentManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->enabledChannels()]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->payments()->orderByDesc('id')->paginate(20),
        ]);
    }

    public function store(Request $request, PaymentService $service): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => 'required|integer|min:1|max:100000000',
            'channel' => 'nullable|string|in:alipay,wechat,mock',
            'bill_id' => 'nullable|integer|exists:bills,id',
            'subject' => 'nullable|string|max:120',
        ]);

        if (! empty($validated['bill_id'])) {
            $bill = Bill::where('user_id', $request->user()->id)->find($validated['bill_id']);
            if (! $bill) {
                return response()->json(['error' => 'bill not found'], 404);
            }
        }

        try {
            $payment = $service->create(
                $request->user(),
                (int) $validated['amount_cents'],
                (string) ($validated['channel'] ?? config('payments.default_channel')),
                (string) ($validated['subject'] ?? '账户充值'),
                isset($validated['bill_id']) ? (int) $validated['bill_id'] : null,
            );
        } catch (PaymentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'create payment failed: '.$e->getMessage()], 502);
        }

        return response()->json(['data' => $payment], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json(['data' => $payment]);
    }

    /**
     * 仅 mock 渠道 + 非生产：模拟渠道到账（联调用）
     */
    public function mockPay(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        if (! config('payments.mock_enabled')) {
            return response()->json(['error' => 'mock payment is disabled'], 403);
        }
        if ($payment->user_id !== $request->user()->id || $payment->channel !== Payment::CHANNEL_MOCK) {
            return response()->json(['error' => 'not a mock payment of this user'], 403);
        }
        if (! $payment->isPending()) {
            return response()->json(['error' => 'payment is not pending'], 422);
        }

        $service->markPaid($payment, 'MOCK-'.$payment->out_trade_no, ['mock' => true]);

        return response()->json(['data' => $payment->fresh()]);
    }
}
