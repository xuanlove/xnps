<?php

namespace App\Services\Portal;

use App\Models\Approval;
use App\Models\Bill;
use App\Models\FlowBaseline;
use App\Models\FlowMonthly;
use App\Models\NpsClient;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 计费引擎（DESIGN.md §8.2 / §8.3）
 *
 * 关键语义：nps 的 flow_limit 是生命周期累计值（从不重置），
 * 不能直接当月度配额——月度配额由本服务执行：
 *
 *   每月 1 号 00:00   recordBaselines()  为所有归属 client 建采样基线
 *   每次 flow 采样后  accumulateFlow()   增量累加到 flow_monthly（防重启漏计）
 *   超额时           enforceQuota()     按 config(nps.quota_action) 执行：
 *                       throttle —— 调低 rate_limit
 *                       disable  —— changestatus=0 踢下线
 *                       bill     —— 仅记账，月末结算超额费
 *   每月 1 号 00:05   settleMonthly()    生成上月账单 + 钱包扣款
 */
class BillingService
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    // ------------------------------------------------------------------
    // 月度用量
    // ------------------------------------------------------------------

    /**
     * flow 采样后增量累加（由 SyncEngine::syncFlow 回调）：
     * 增量 = 当前 - 上次快照；当前 < 上次（nps 重启清零）时增量 = 当前
     *
     * @param  array  $flows  /ext/flow 的 flows：[{client_id, in, out}]
     * @param  int  $serverId  采样来源服务器
     */
    public function accumulateFlow(int $serverId, array $flows): void
    {
        $period = self::currentPeriod();

        // 单事务处理整片采样（AUDIT P2-1）：减少 N 次事务提交；
        // 行锁仍按 client 基线加锁，保证 delta 计算与快照回写原子。
        DB::transaction(function () use ($serverId, $flows, $period) {
            foreach ($flows as $flow) {
                $client = NpsClient::where('server_id', $serverId)
                    ->where('remote_id', (int) ($flow['client_id'] ?? 0))
                    ->whereNotNull('user_id')
                    ->first();
                if (! $client) {
                    continue; // 无归属的 client 不计费
                }

                $currentIn = (int) ($flow['in'] ?? 0);
                $currentOut = (int) ($flow['out'] ?? 0);

                $baseline = FlowBaseline::where('client_id', $client->id)
                    ->where('period', $period)
                    ->lockForUpdate()->first();

                if (! $baseline) {
                    // 首次采样：只建基线不计数
                    FlowBaseline::create([
                        'client_id' => $client->id,
                        'period' => $period,
                        'last_in' => $currentIn,
                        'last_out' => $currentOut,
                    ]);

                    continue;
                }

                $deltaIn = max(0, $currentIn - $baseline->last_in);
                $deltaOut = max(0, $currentOut - $baseline->last_out);
                // nps 重启清零：current < last 时按 current 计（从 0 重新累计）
                if ($currentIn < $baseline->last_in) {
                    $deltaIn = $currentIn;
                }
                if ($currentOut < $baseline->last_out) {
                    $deltaOut = $currentOut;
                }

                if ($deltaIn > 0 || $deltaOut > 0) {
                    $monthly = FlowMonthly::firstOrCreate([
                        'user_id' => $client->user_id,
                        'period' => $period,
                    ]);
                    $monthly->increment('used_in', $deltaIn);
                    $monthly->increment('used_out', $deltaOut);
                }

                $baseline->update(['last_in' => $currentIn, 'last_out' => $currentOut]);
            }
        });
    }

    /**
     * 超额检查与执行（accumulateFlow 后调用；幂等：quota_action 已记录则跳过）
     */
    public function enforceQuota(User $user): void
    {
        $plan = app(ApprovalService::class)->currentPlan($user);
        if (! $plan || $plan->monthly_flow_mb === 0) {
            return; // 无套餐或流量不限
        }

        $monthly = FlowMonthly::where('user_id', $user->id)
            ->where('period', self::currentPeriod())->first();
        if (! $monthly) {
            return;
        }

        $limitBytes = $plan->monthly_flow_mb * 1024 * 1024;
        if ($monthly->usedBytes() <= $limitBytes) {
            return;
        }

        // 已执行过动作则跳过（幂等）
        if ($monthly->quota_action !== null && $monthly->quota_action !== 'bill') {
            return;
        }

        $action = (string) config('nps.quota_action');
        try {
            $applied = match ($action) {
                'throttle' => $this->throttleUserClients($user, $plan),
                'disable' => $this->disableUserClients($user),
                'bill' => true, // 仅记账
                default => true,
            };
        } catch (NpsException $e) {
            Log::error("quota action failed for user {$user->id}: {$e->getMessage()}");

            return;
        }

        $monthly->update([
            'quota_action' => $applied ? $action : 'failed',
            'quota_action_at' => now(),
        ]);
        Log::info("quota exceeded for user {$user->id}: action={$action} used={$monthly->usedBytes()} limit={$limitBytes}");
    }

    // ------------------------------------------------------------------
    // 月度结算
    // ------------------------------------------------------------------

    /**
     * 结算一个用户一个账期：生成账单 + 钱包扣款
     *
     * @param  string|null  $period  账期（空=上月）
     */
    public function settleMonthly(User $user, ?string $period = null): ?Bill
    {
        $period ??= now()->subMonth()->startOfMonth()->format('Y-m');

        // 幂等：已有账单直接返回
        $existing = Bill::where('user_id', $user->id)->where('period', $period)->first();
        if ($existing) {
            return $existing;
        }

        // currentPlan 返回 Plan 模型（无订阅为 null）
        $plan = app(ApprovalService::class)->currentPlan($user);

        $monthly = FlowMonthly::where('user_id', $user->id)->where('period', $period)->first();
        $usedBytes = $monthly?->usedBytes() ?? 0;

        $planFee = $plan->monthly_fee_cents ?? 0;
        $includedBytes = ($plan->monthly_flow_mb ?? 0) * 1024 * 1024;
        $overageBytes = max(0, $usedBytes - $includedBytes);

        // 超额费：按 GB 计（向上取整到 MB 粒度换算）
        $pricePerGb = (int) config('nps.overage_price_cents_per_gb');
        $overageFee = (int) ceil($overageBytes / (1024 * 1024 * 1024) * $pricePerGb);

        $totalFee = $planFee + $overageFee;
        $startedAt = microtime(true);

        return DB::transaction(function () use ($user, $period, $plan, $planFee, $usedBytes, $includedBytes, $overageBytes, $overageFee, $totalFee, $startedAt) {
            $bill = Bill::create([
                'user_id' => $user->id,
                'period' => $period,
                'plan_id' => $plan?->id,
                'plan_fee_cents' => $planFee,
                'used_bytes' => $usedBytes,
                'included_bytes' => $includedBytes,
                'overage_bytes' => $overageBytes,
                'overage_fee_cents' => $overageFee,
                'total_fee_cents' => $totalFee,
                'status' => 'pending',
            ]);

            // 钱包扣款（余额可为负 = 欠费）
            if ($totalFee > 0) {
                $paid = $this->chargeWallet($user, $totalFee, "bill:{$bill->id}", "monthly bill {$period}");
                $bill->update([
                    'status' => $paid ? 'paid' : 'unpaid',
                    'paid_at' => $paid ? now() : null,
                ]);
            } else {
                $bill->update(['status' => 'paid', 'paid_at' => now()]);
            }

            // 结算耗时（I3 /metrics：nps_billing_settle_duration_seconds）
            $bill->update([
                'settle_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $bill->fresh();
        });
    }

    /**
     * 月度结算批处理（scheduler 每月 1 号触发）：结算上月所有有订阅/用量的用户
     *
     * @return array{users: int, bills: int, unpaid: int}
     */
    public function settleAll(?string $period = null): array
    {
        $period ??= now()->subMonth()->startOfMonth()->format('Y-m');

        // 有订阅或有用量的用户
        $userIds = collect()
            ->merge(\App\Models\PlanSubscription::where('status', 'active')->pluck('user_id'))
            ->merge(FlowMonthly::where('period', $period)->pluck('user_id'))
            ->unique();

        $stats = ['users' => 0, 'bills' => 0, 'unpaid' => 0];
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            $stats['users']++;
            $bill = $this->settleMonthly($user, $period);
            if ($bill) {
                $stats['bills']++;
                if ($bill->status === 'unpaid') {
                    $stats['unpaid']++;
                }
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // 钱包
    // ------------------------------------------------------------------

    /**
     * 充值（管理员操作）
     */
    public function recharge(User $user, int $amountCents, string $remark = '', ?string $refType = null): Wallet
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('amount must be positive');
        }

        return DB::transaction(function () use ($user, $amountCents, $remark, $refType) {
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);
            $wallet->increment('balance_cents', $amountCents);
            $this->recordTransaction($wallet, 'recharge', $amountCents, $refType, $remark);

            return $wallet->fresh();
        });
    }

    /**
     * 扣款：余额充足返回 true；不足仍扣（负余额=欠费）返回 false
     */
    public function chargeWallet(User $user, int $amountCents, ?string $refType = null, string $remark = ''): bool
    {
        return DB::transaction(function () use ($user, $amountCents, $refType, $remark) {
            // 行锁（AUDIT M2）：并发扣款时防止 paid 判定基于过期余额
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $wallet) {
                $wallet = Wallet::create(['user_id' => $user->id, 'balance_cents' => 0]);
            }
            $before = $wallet->balance_cents;
            $wallet->decrement('balance_cents', $amountCents);
            $this->recordTransaction($wallet, 'charge', -$amountCents, $refType, $remark);

            // 扣款前余额充足 = paid
            return $before >= $amountCents;
        });
    }

    private function recordTransaction(Wallet $wallet, string $type, int $amountCents, ?string $refType, string $remark): void
    {
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount_cents' => $amountCents,
            'balance_after_cents' => $wallet->fresh()->balance_cents,
            'ref_type' => $refType,
            'remark' => $remark,
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // 超额动作实现
    // ------------------------------------------------------------------

    /**
     * throttle：调低该用户全部 client 的 rate_limit 至套餐限速值
     */
    private function throttleUserClients(User $user, $plan): bool
    {
        $rate = $plan->rate_limit_kb > 0 ? $plan->rate_limit_kb : 64; // 兜底 64KB/S
        $applied = true;
        foreach ($user->npsClients as $client) {
            $resp = $this->clientFactory->for($client->server)
                ->editClient($client->remote_id, ['rate_limit' => (string) $rate]);
            $applied = $applied && $resp->ok;
        }

        return $applied;
    }

    /**
     * disable：changestatus=0 立即踢下线（DESIGN.md §2.4-2）
     */
    private function disableUserClients(User $user): bool
    {
        $applied = true;
        foreach ($user->npsClients as $client) {
            $resp = $this->clientFactory->for($client->server)
                ->changeClientStatus($client->remote_id, false);
            $applied = $applied && $resp->ok;
        }

        return $applied;
    }
}
