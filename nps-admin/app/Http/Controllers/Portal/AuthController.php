<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Portal\ApprovalService;
use App\Services\Portal\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * P2 门户认证（DESIGN.md §8.4）：Sanctum token；用户永不接触 nps auth_key
 */
class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        // 自动订阅默认套餐（config nps.default_plan_id）
        $defaultPlanId = (int) config('nps.default_plan_id');
        if ($defaultPlanId > 0 && \App\Models\Plan::where('id', $defaultPlanId)->where('active', true)->exists()) {
            PlanSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $defaultPlanId,
                'started_at' => now(),
                'status' => 'active',
            ]);
        }

        // 注册赠送余额
        $credit = (int) config('nps.welcome_credit_cents');
        if ($credit > 0) {
            app(BillingService::class)->recharge($user, $credit, 'welcome credit');
        }

        // token 30 天过期（AUDIT M7：Sanctum 默认永不过期）
        $token = $user->createToken('portal', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'data' => ['user' => $user, 'token' => $token],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => '账号或密码错误']);
        }

        // token 30 天过期（AUDIT M7：Sanctum 默认永不过期）
        $token = $user->createToken('portal', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'data' => ['user' => $user, 'token' => $token],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['logout' => true]]);
    }

    /**
     * 我的信息：套餐 + 余额 + 本月用量
     */
    public function me(Request $request, ApprovalService $approvalService): JsonResponse
    {
        $user = $request->user();
        $plan = $approvalService->currentPlan($user);
        $monthly = \App\Models\FlowMonthly::where('user_id', $user->id)
            ->where('period', BillingService::currentPeriod())->first();

        return response()->json([
            'data' => [
                'user' => $user,
                'plan' => $plan,
                'wallet' => $user->wallet,
                'monthly_usage' => $monthly ? [
                    'period' => $monthly->period,
                    'used_bytes' => $monthly->usedBytes(),
                    'quota_action' => $monthly->quota_action,
                ] : null,
                'client_count' => $user->npsClients()->count(),
            ],
        ]);
    }
}
