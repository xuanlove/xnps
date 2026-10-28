<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理端：支付流水与账单查询
 */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with('user:id,name,email')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->orderByDesc('id');

        return response()->json(['data' => $query->paginate(min(100, $request->integer('per_page', 30)))]);
    }

    public function bills(Request $request): JsonResponse
    {
        $query = Bill::with('user:id,name,email', 'plan:id,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('period'), fn ($q, $v) => $q->where('period', $v))
            ->orderByDesc('period')->orderByDesc('id');

        return response()->json(['data' => $query->paginate(min(100, $request->integer('per_page', 30)))]);
    }
}
