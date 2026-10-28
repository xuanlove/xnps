<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 支付渠道异步回调（公开端点，验签失败一律拒绝）
 */
class PaymentNotifyController extends Controller
{
    public function alipay(Request $request, PaymentService $service)
    {
        try {
            $service->handleNotify('alipay', $request);
        } catch (PaymentException $e) {
            Log::warning('alipay notify rejected: '.$e->getMessage());

            return response('fail', 400);
        }

        // 支付宝要求返回纯文本 success，否则会持续重试
        return response('success');
    }

    public function wechat(Request $request, PaymentService $service)
    {
        try {
            $service->handleNotify('wechat', $request);
        } catch (PaymentException $e) {
            Log::warning('wechat notify rejected: '.$e->getMessage());

            return response()->json(['code' => 'FAIL', 'message' => 'verify failed'], 400);
        }

        return response()->json(['code' => 'SUCCESS', 'message' => '']);
    }
}
