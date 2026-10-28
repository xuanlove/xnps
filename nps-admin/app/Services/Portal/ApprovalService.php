<?php

namespace App\Services\Portal;

use App\Jobs\ProvisionApprovalJob;
use App\Models\Approval;
use App\Models\Bill;
use App\Models\NpsTunnel;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 审批流（DESIGN.md §8.1 状态机）：
 *
 *   submitted → approved → provisioning → provisioned
 *   submitted → rejected / expired；provisioning → failed
 *
 * 自动审批规则（config nps.auto_approve，规则集由运营定义）：
 *   require_active_subscription / require_no_unpaid_bills / max_tunnels_within_plan
 * 全部通过 → 自动 approved 并派发开通；任一失败 → 保持 submitted 人工审批
 */
class ApprovalService
{
    /**
     * 提交申请：生成幂等键，尝试自动审批
     *
     * @return Approval
     */
    public function submit(User $user, string $type, array $payload): Approval
    {
        if (! in_array($type, [Approval::TYPE_CLIENT, Approval::TYPE_TUNNEL], true)) {
            throw new \InvalidArgumentException('type must be client or tunnel');
        }

        $approval = Approval::create([
            'user_id' => $user->id,
            'type' => $type,
            'payload' => $payload,
            'status' => Approval::STATUS_SUBMITTED,
            'operation_key' => (string) Str::uuid(),
        ]);

        // 自动审批尝试
        if ($this->tryAutoApprove($approval)) {
            $this->approve($approval, null);
        }

        return $approval->fresh();
    }

    /**
     * 审批通过（人工或自动）：approved + 派发开通 Job
     */
    public function approve(Approval $approval, ?User $approver): void
    {
        if ($approval->status !== Approval::STATUS_SUBMITTED) {
            throw new \DomainException("approval {$approval->id} is not in submitted state");
        }

        $approval->update([
            'status' => Approval::STATUS_APPROVED,
            'approver_id' => $approver?->id,
            'reviewed_at' => now(),
        ]);

        ProvisionApprovalJob::dispatch($approval->id)->onQueue('nps-provision');
    }

    /**
     * 驳回
     */
    public function reject(Approval $approval, User $approver, string $reason): void
    {
        if ($approval->status !== Approval::STATUS_SUBMITTED) {
            throw new \DomainException("approval {$approval->id} is not in submitted state");
        }

        $approval->update([
            'status' => Approval::STATUS_REJECTED,
            'approver_id' => $approver->id,
            'reviewed_at' => now(),
            'reject_reason' => $reason,
        ]);
    }

    /**
     * 过期扫描：submitted 超时未审批自动 expired（scheduler 每小时）
     *
     * @return int 过期条数
     */
    public function expireStale(): int
    {
        $deadline = now()->subHours((int) config('nps.approval_expire_hours'));

        return Approval::where('status', Approval::STATUS_SUBMITTED)
            ->where('created_at', '<', $deadline)
            ->update(['status' => Approval::STATUS_EXPIRED]);
    }

    // ------------------------------------------------------------------
    // 自动审批规则
    // ------------------------------------------------------------------

    /**
     * 自动审批：全部规则通过返回 true（approved 由调用方落地）
     */
    public function tryAutoApprove(Approval $approval): bool
    {
        $rules = (array) config('nps.auto_approve');
        if (empty($rules['enabled'])) {
            return false;
        }

        $user = $approval->user;

        if (! empty($rules['require_active_subscription'])) {
            if (! $user->activeSubscription()->exists()) {
                return false;
            }
        }

        if (! empty($rules['require_no_unpaid_bills'])) {
            if (Bill::where('user_id', $user->id)->where('status', 'unpaid')->exists()) {
                return false;
            }
        }

        if (! empty($rules['max_tunnels_within_plan'])
            && $approval->type === Approval::TYPE_TUNNEL) {
            if (! $this->withinTunnelQuota($user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 套餐隧道配额校验：现有隧道 + pending/provisioning 申请数 < plan.max_tunnels
     */
    public function withinTunnelQuota(User $user): bool
    {
        $subscription = $user->activeSubscription()->with('plan')->first();
        if (! $subscription) {
            return false; // 无套餐：不自动批
        }
        $plan = $subscription->plan;
        if ($plan->max_tunnels === 0) {
            return true; // 0 = 不限
        }

        $current = NpsTunnel::whereIn(
            'client_id',
            $user->npsClients()->select('id')->pluck('id'),
        )->count();

        $pending = Approval::where('user_id', $user->id)
            ->where('type', Approval::TYPE_TUNNEL)
            ->whereIn('status', [
                Approval::STATUS_SUBMITTED,
                Approval::STATUS_APPROVED,
                Approval::STATUS_PROVISIONING,
            ])
            ->count();

        return ($current + $pending) < $plan->max_tunnels;
    }

    /**
     * 用户当前套餐（无订阅返回 null）
     */
    public function currentPlan(User $user): ?Plan
    {
        $sub = $user->activeSubscription()->with('plan')->first();

        return $sub?->plan;
    }
}
