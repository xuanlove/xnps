<?php

namespace App\Jobs;

use App\Models\Approval;
use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Models\NpsTunnel;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Sync\SyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 审批开通 Job（DESIGN.md §8.1 provisioning 幂等）：
 *
 * 幂等三层防护（operation_key 语义）：
 *   1. status == provisioned → 直接返回
 *   2. client：按 payload.vkey 查镜像已存在 → 绑定归属并标记（恢复路径）
 *   3. tunnel：按 (client, port, mode) 查镜像已存在 → 标记
 *
 * 失败 → status=failed + fail_reason（人工介入；重试由人工触发）
 */
class ProvisionApprovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $approvalId,
    ) {}

    public function handle(ProvisioningService $provisioning): void
    {
        $approval = Approval::find($this->approvalId);
        if (! $approval) {
            return;
        }

        // 幂等第一层：已完成直接返回
        if ($approval->status === Approval::STATUS_PROVISIONED) {
            return;
        }
        if (! in_array($approval->status, [Approval::STATUS_APPROVED, Approval::STATUS_PROVISIONING], true)) {
            return; // rejected/expired 等不再处理
        }

        // 幂等第二层（最强）：payload 已回写 resource_id（上次开通成功但标记前崩溃的恢复路径）
        $payload = $approval->payload;
        if (! empty($payload['resource_id'])) {
            $resource = $payload['resource_type'] === 'client'
                ? NpsClient::find($payload['resource_id'])
                : NpsTunnel::find($payload['resource_id']);
            if ($resource) {
                $this->markProvisioned($approval, $resource);

                return;
            }
        }

        $approval->update(['status' => Approval::STATUS_PROVISIONING]);

        try {
            match ($approval->type) {
                Approval::TYPE_CLIENT => $this->provisionClient($approval, $provisioning),
                Approval::TYPE_TUNNEL => $this->provisionTunnel($approval, $provisioning),
            };
        } catch (\Throwable $e) {
            // 队列重试（tries=3）期间保持 provisioning；
            // 最终失败（attempt 达上限）标记 failed 供人工介入
            $failed = $this->attempts() >= $this->tries;
            $approval->update([
                'status' => $failed ? Approval::STATUS_FAILED : Approval::STATUS_PROVISIONING,
                'fail_reason' => $e->getMessage(),
            ]);
            Log::error("provision approval {$approval->id} failed: {$e->getMessage()}");

            if ($failed) {
                return;
            }
            throw $e; // 触发队列重试
        }
    }

    /**
     * 开通 client：vkey = operation_key 派生（幂等天然成立——远端 vkey 查重）
     */
    private function provisionClient(Approval $approval, ProvisioningService $provisioning): void
    {
        $payload = $approval->payload;
        $user = $approval->user;
        $server = NpsServer::where('status', 'active')->findOrFail($payload['server_id'] ?? 1);

        // 幂等第二层：恢复路径——镜像已存在该 vkey（上次开通成功但标记失败）
        $vkey = $payload['vkey'] ?? $this->deriveVkey($approval);
        $existing = NpsClient::where('server_id', $server->id)->where('vkey', $vkey)->first();
        if ($existing) {
            $existing->update(['user_id' => $user->id]);
            $this->markProvisioned($approval, $existing);

            return;
        }

        // 套餐参数映射到 nps（双层防御：Laravel 策略层 + nps 强制层）
        $plan = app(\App\Services\Portal\ApprovalService::class)->currentPlan($user);
        $params = [
            'vkey' => $vkey,
            'remark' => $payload['remark'] ?? "user-{$user->id}",
            'rate_limit' => (string) ($plan->rate_limit_kb ?? 0),
            'max_conn' => (string) ($plan->max_conn ?? 0),
            'max_tunnel' => (string) ($plan->max_tunnels ?? 0),
            'crypt' => ! empty($payload['crypt']) ? 1 : 0,
            'compress' => ! empty($payload['compress']) ? 1 : 0,
        ];

        $client = $provisioning->createClient($server, $params);
        $client->update(['user_id' => $user->id]);
        $this->markProvisioned($approval, $client);
    }

    /**
     * 开通隧道：幂等按 (client, port, mode) 查镜像
     */
    private function provisionTunnel(Approval $approval, ProvisioningService $provisioning): void
    {
        $payload = $approval->payload;
        $user = $approval->user;

        $client = NpsClient::where('user_id', $user->id)
            ->findOrFail($payload['client_id'] ?? 0);

        // 幂等第三层：恢复路径——按 (client, mode, target, port) 精确匹配
        //（不能只用 client+mode：自动分配端口的多个隧道申请无法区分）
        $existing = NpsTunnel::where('server_id', $client->server_id)
            ->where('client_id', $client->id)
            ->where('mode', $payload['mode'] ?? 'tcp')
            ->where('target', $payload['target'] ?? '')
            ->when(! empty($payload['port']), fn ($q) => $q->where('port', (int) $payload['port']))
            ->orderByDesc('remote_id')->first();
        if ($existing) {
            $this->markProvisioned($approval, $existing);

            return;
        }

        $tunnel = $provisioning->createTunnel($client, [
            'mode' => $payload['mode'] ?? 'tcp',
            'target' => $payload['target'] ?? '',
            'remark' => $payload['remark'] ?? '',
            'password' => $payload['password'] ?? '',
            'port' => $payload['port'] ?? null,
        ]);
        $this->markProvisioned($approval, $tunnel);
    }

    private function markProvisioned(Approval $approval, $resource): void
    {
        // 回写 resource_id 到 payload：最强幂等键（重试恢复路径）
        $payload = $approval->payload;
        $payload['resource_id'] = $resource->id;
        $payload['resource_type'] = $approval->type === Approval::TYPE_CLIENT ? 'client' : 'tunnel';
        $approval->payload = $payload;

        $approval->update([
            'status' => Approval::STATUS_PROVISIONED,
            'provisioned_at' => now(),
            'fail_reason' => null,
        ]);
        Log::info("approval {$approval->id} provisioned: ".get_class($resource)."#{$resource->id}");
    }

    /**
     * vkey 派生自 operation_key：重试时同一 approval 生成同一 vkey
     */
    private function deriveVkey(Approval $approval): string
    {
        return strtolower(substr(md5('nps-admin:'.$approval->operation_key), 0, 16));
    }
}
