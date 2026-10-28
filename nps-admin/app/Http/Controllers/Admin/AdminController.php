<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Plan;
use App\Models\User;
use App\Services\Portal\ApprovalService;
use App\Services\Portal\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理端（is_admin）：审批、套餐、充值、结算
 */
class AdminController extends Controller
{
    // ------------------------------------------------------------------
    // 审批
    // ------------------------------------------------------------------

    public function approvals(Request $request): JsonResponse
    {
        $query = Approval::with('user:id,name,email')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('id');

        return response()->json(['data' => $query->paginate(50)]);
    }

    public function approve(Request $request, Approval $approval, ApprovalService $approvalService): JsonResponse
    {
        try {
            $approvalService->approve($approval, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $approval->fresh()]);
    }

    public function reject(Request $request, Approval $approval, ApprovalService $approvalService): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:255']);
        try {
            $approvalService->reject($approval, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $approval->fresh()]);
    }

    // ------------------------------------------------------------------
    // 套餐
    // ------------------------------------------------------------------

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('id')->get()]);
    }

    public function planStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'monthly_fee_cents' => 'nullable|integer|min:0',
            'max_tunnels' => 'nullable|integer|min:0',
            'monthly_flow_mb' => 'nullable|integer|min:0',
            'rate_limit_kb' => 'nullable|integer|min:0',
            'max_conn' => 'nullable|integer|min:0',
        ]);

        return response()->json(['data' => Plan::create($validated)], 201);
    }

    // ------------------------------------------------------------------
    // 用户与钱包
    // ------------------------------------------------------------------

    public function users(): JsonResponse
    {
        return response()->json([
            'data' => User::with('wallet', 'activeSubscription.plan')
                ->withCount('npsClients')
                ->orderByDesc('id')->paginate(50),
        ]);
    }

    public function recharge(Request $request, User $user, BillingService $billing): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => 'required|integer|min:1',
            'remark' => 'nullable|string|max:255',
        ]);

        $wallet = $billing->recharge($user, $validated['amount_cents'], $validated['remark'] ?? '');

        return response()->json(['data' => $wallet]);
    }

    // ------------------------------------------------------------------
    // 结算
    // ------------------------------------------------------------------

    /**
     * 手动触发月度结算（scheduler 每月 1 号自动执行）
     */
    public function settle(Request $request, BillingService $billing): JsonResponse
    {
        $period = $request->input('period') ?? now()->subMonth()->startOfMonth()->format('Y-m');
        $stats = $billing->settleAll($period);

        return response()->json(['data' => $stats + ['period' => $period]]);
    }
}
