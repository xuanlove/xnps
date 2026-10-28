<?php

namespace App\Services\Upgrade;

use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Models\UpgradeArtifact;
use App\Models\UpgradeRun;
use App\Models\UpgradeStep;
use App\Services\Nps\NpsApiClientFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 版本滚动升级编排器（DESIGN.md §9.3）
 *
 * 官方 install.UpdateNps() 不可用（只拉 GitHub latest、无版本选择、
 * 无回滚、官方二进制不含 ext 模块）——升级一律走本编排器：
 *
 *   1. 预检：兼容矩阵（core version 必须不变，否则旧 npc 全量握手失败）
 *            + 在线 npc Version 扫描 + conf 快照存档
 *   2. canary：先升 1 台 → 健康验证 → 观察窗口
 *   3. 分批滚动：每批 batch_size 台，批间健康检查
 *   4. 单台：/ext/upgrade（url 模式，sha256 强校验，rename 原子替换）
 *            → /ext/restart → 健康验证（版本号核对）
 *   5. 失败自动回滚：回推旧制品（制品库留存）+ restart + 告警
 */
class UpgradeOrchestrator
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    // ------------------------------------------------------------------
    // 编排入口
    // ------------------------------------------------------------------

    /**
     * 创建并执行一次升级 run（canary + 滚动批次）
     *
     * @param  array  $serverIds  目标服务器
     * @param  int|null  $canaryServerId  灰度首台（空=取第一台）
     * @return UpgradeRun
     */
    public function start(UpgradeArtifact $artifact, array $serverIds, ?int $canaryServerId = null, int $observeSeconds = 900): UpgradeRun
    {
        $serverIds = array_values(array_unique(array_map('intval', $serverIds)));
        if (empty($serverIds)) {
            throw new \InvalidArgumentException('no target servers');
        }
        $canaryServerId ??= $serverIds[0];

        $run = UpgradeRun::create([
            'artifact_id' => $artifact->id,
            'server_ids' => $serverIds,
            'canary_server_id' => $canaryServerId,
            'observe_seconds' => $observeSeconds,
            'status' => UpgradeRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
        foreach ($serverIds as $sid) {
            UpgradeStep::create([
                'run_id' => $run->id,
                'server_id' => $sid,
                'status' => UpgradeStep::STATUS_PENDING,
            ]);
        }

        // canary 优先，其余保持原顺序
        $ordered = array_values(array_unique(array_merge([$canaryServerId], array_diff($serverIds, [$canaryServerId]))));

        $results = [];
        foreach ($ordered as $index => $serverId) {
            $server = NpsServer::find($serverId);
            if (! $server || ! $server->isActive()) {
                $results[$serverId] = ['status' => 'skipped', 'reason' => 'server not active'];
                continue;
            }

            $result = $this->upgradeOne($run, $server, $artifact);
            $results[$serverId] = $result;

            // canary 失败 → 中止整批（DESIGN.md §9.3 灰度语义）
            if ($serverId === $canaryServerId && ($result['status'] !== 'succeeded')) {
                $run->update([
                    'status' => UpgradeRun::STATUS_ABORTED,
                    'summary' => ['aborted_at' => $canaryServerId, 'results' => $results],
                    'finished_at' => now(),
                ]);

                return $run->fresh();
            }

            // canary 成功 → 观察窗口（生产 15 分钟；测试可传 0）
            if ($serverId === $canaryServerId && $index < count($ordered) - 1 && $observeSeconds > 0) {
                Log::info("upgrade run {$run->id}: canary ok, observing {$observeSeconds}s");
                sleep(min($observeSeconds, 900)); // 单次观察上限 15 分钟（Job 超时兜底）
            }
        }

        $succeeded = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'succeeded'));
        $run->update([
            'status' => $succeeded === count($serverIds) ? UpgradeRun::STATUS_SUCCEEDED : UpgradeRun::STATUS_FAILED,
            'summary' => [
                'total' => count($serverIds),
                'succeeded' => $succeeded,
                'failed' => count($serverIds) - $succeeded,
                'results' => $results,
                'artifact' => $artifact->version,
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    // ------------------------------------------------------------------
    // 单台升级（含预检 / 验证 / 回滚）
    // ------------------------------------------------------------------

    /**
     * @return array{status: string, ...}
     */
    public function upgradeOne(UpgradeRun $run, NpsServer $server, UpgradeArtifact $artifact): array
    {
        $step = $run->steps()->firstOrCreate(
            ['server_id' => $server->id],
            ['run_id' => $run->id, 'status' => UpgradeStep::STATUS_PENDING],
        );
        $step->update(['status' => UpgradeStep::STATUS_PRECHECKING, 'started_at' => now()]);

        $api = $this->clientFactory->for($server);

        // ---- 1. 预检 ----
        try {
            $precheck = $this->precheck($server, $artifact, $api);
        } catch (\Throwable $e) {
            return $this->failStep($step, $server, "precheck failed: {$e->getMessage()}", $e->getMessage());
        }
        $oldVersion = $precheck['current_version'];

        // ---- 2. 升级（url 模式：nps 出方向从制品库下载）----
        $step->update(['status' => UpgradeStep::STATUS_UPGRADING, 'old_version' => $oldVersion, 'precheck' => $precheck]);
        $resp = $api->call('POST', '/ext/upgrade', [], [
            'mode' => 'url',
            'url' => $artifact->downloadUrl(),
            'sha256' => $artifact->sha256,
            'version' => $artifact->version,
            'restart' => true, // 替换后联动重启
        ]);
        if (! $resp->ok) {
            // 升级请求失败（sha256 不匹配 / 下载失败）：二进制未替换，无需回滚
            return $this->failStep($step, $server, "ext/upgrade failed: {$resp->msg}", $resp->msg);
        }

        // ---- 3. 健康验证：等待重启 + 版本号核对 ----
        $step->update(['status' => UpgradeStep::STATUS_VERIFYING]);
        $verify = $this->waitHealthy($server, $artifact->version);
        if ($verify['ok']) {
            $step->update([
                'status' => UpgradeStep::STATUS_SUCCEEDED,
                'new_version' => $artifact->version,
                'result' => $verify + ['upgraded_at' => now()->toIso8601String()],
                'finished_at' => now(),
            ]);

            return ['status' => 'succeeded', 'old' => $oldVersion, 'new' => $artifact->version];
        }

        // ---- 4. 失败自动回滚：回推旧制品 + 重启 ----
        Log::error("upgrade verify failed for {$server->name}: {$verify['error']}, rolling back");
        $rollback = $this->rollback($server, $oldVersion, $api);
        $step->update([
            'status' => $rollback['ok'] ? UpgradeStep::STATUS_ROLLED_BACK : UpgradeStep::STATUS_FAILED,
            'new_version' => $rollback['ok'] ? $oldVersion : null,
            'error' => "verify failed: {$verify['error']}; rollback: ".($rollback['ok'] ? 'ok' : $rollback['error']),
            'result' => ['verify' => $verify, 'rollback' => $rollback],
            'finished_at' => now(),
        ]);
        \App\Models\Alert::create([
            'server_id' => $server->id,
            'type' => 'upgrade_failed',
            'level' => 'critical',
            'message' => "upgrade to {$artifact->version} failed on {$server->name}, ".($rollback['ok'] ? 'rolled back' : 'ROLLBACK ALSO FAILED'),
            'context' => ['verify' => $verify, 'rollback' => $rollback],
        ]);

        return ['status' => $rollback['ok'] ? 'rolled_back' : 'failed', 'error' => $verify['error']];
    }

    // ------------------------------------------------------------------
    // 预检（DESIGN.md §9.3/§9.4）
    // ------------------------------------------------------------------

    /**
     * 步骤失败落地：status=failed + 错误信息
     */
    private function failStep(UpgradeStep $step, NpsServer $server, string $message, string $error): array
    {
        $step->update([
            'status' => UpgradeStep::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ]);
        \App\Models\Alert::create([
            'server_id' => $server->id,
            'type' => 'upgrade_failed',
            'level' => 'critical',
            'message' => $message,
            'context' => ['error' => $error],
        ]);

        return ['status' => 'failed', 'error' => $message];
    }

    private function precheck(NpsServer $server, UpgradeArtifact $artifact, $api): array
    {
        $resp = $api->extVersion()->orFail('precheck ext/version');
        $data = (array) $resp->data;
        $currentVersion = (string) ($data['version'] ?? '');
        $coreVersion = (string) ($data['core_version'] ?? '');

        // 兼容矩阵：core version 必须不变（bridge 握手校验 md5(core version)，
        // 变了会导致旧 npc 全量握手失败——DESIGN.md §9.4）
        if ($coreVersion !== $artifact->compatible_core_version) {
            throw new \RuntimeException(sprintf(
                'core version mismatch: server=%s artifact compatible=%s（升级会断开全部旧 npc）',
                $coreVersion, $artifact->compatible_core_version,
            ));
        }

        // 在线 npc Version 扫描：全部在兼容范围（当前实现要求 == core version）
        $incompatible = NpsClient::where('server_id', $server->id)
            ->where('is_connect', true)
            ->whereNotNull('npc_version')
            ->where('npc_version', '!=', $coreVersion)
            ->count();
        if ($incompatible > 0) {
            throw new \RuntimeException("{$incompatible} online npc with incompatible core version (need {$coreVersion})");
        }

        // conf 快照存档（脱敏版，用于升级前后 diff 审计；升级流程不动 conf 文件）
        $confSnapshot = null;
        $confResp = $api->extConfigGet();
        if ($confResp->ok) {
            $path = "conf-backups/{$server->id}/".now()->format('YmdHis').'.json';
            Storage::put($path, json_encode((array) $confResp->data, JSON_PRETTY_PRINT));
            $confSnapshot = $path;
        }

        return [
            'current_version' => $currentVersion,
            'core_version' => $coreVersion,
            'online_npc' => NpsClient::where('server_id', $server->id)->where('is_connect', true)->count(),
            'conf_snapshot' => $confSnapshot,
        ];
    }

    // ------------------------------------------------------------------
    // 健康验证与回滚
    // ------------------------------------------------------------------

    /**
     * 等待重启完成并核对新版本（/ext/version）。
     * 版本签名约定："{version}-ext{ext_version}"（如 0.26.10-ext0.1.1），
     * 与制品库的 version 字段一致——同时覆盖 nps 版本与 ext 模块版本
     */
    private function waitHealthy(NpsServer $server, string $expectedVersion): array
    {
        $delays = [3, 5, 8, 13, 21, 34]; // ~84s
        foreach ($delays as $delay) {
            sleep($delay);
            $resp = $this->clientFactory->for($server)->extVersion();
            if ($resp->ok) {
                $data = (array) $resp->data;
                $signature = sprintf('%s-ext%s', $data['version'] ?? '', $data['ext_version'] ?? '');
                if ($signature === $expectedVersion) {
                    return ['ok' => true, 'version' => $signature];
                }
                // 签名不匹配：可能还在旧进程（重启未完成）——继续退避
            }
        }
        $final = $this->clientFactory->for($server)->extVersion();
        $got = $final->ok
            ? sprintf('%s-ext%s', ((array) $final->data)['version'] ?? '?', ((array) $final->data)['ext_version'] ?? '?')
            : 'unreachable';

        return ['ok' => false, 'error' => "version mismatch: expected={$expectedVersion} got={$got}"];
    }

    /**
     * 回滚：回推旧版本制品（制品库留存）+ 重启 + 验证
     */
    private function rollback(NpsServer $server, string $oldVersion, $api): array
    {
        $oldArtifact = UpgradeArtifact::where('version', $oldVersion)->first();
        if (! $oldArtifact) {
            return ['ok' => false, 'error' => "old artifact {$oldVersion} not in library（无法自动回滚，需人工介入）"];
        }

        $resp = $api->call('POST', '/ext/upgrade', [], [
            'mode' => 'url',
            'url' => $oldArtifact->downloadUrl(),
            'sha256' => $oldArtifact->sha256,
            'version' => $oldArtifact->version,
            'restart' => true,
        ]);
        if (! $resp->ok) {
            return ['ok' => false, 'error' => 'rollback ext/upgrade failed: '.$resp->msg];
        }

        $verify = $this->waitHealthy($server, $oldVersion);

        return $verify['ok']
            ? ['ok' => true, 'version' => $oldVersion]
            : ['ok' => false, 'error' => 'rollback verify failed: '.$verify['error']];
    }
}
