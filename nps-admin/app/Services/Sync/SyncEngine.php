<?php

namespace App\Services\Sync;

use App\Models\NpsClient;
use App\Models\NpsHost;
use App\Models\NpsServer;
use App\Models\NpsTunnel;
use App\Services\Nps\NpsApiClient;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 三层同步引擎（DESIGN.md §6）
 *
 *  L1 写后回读：ProvisioningService 在写操作后直接调用 pullChanges
 *  L2 版本号增量：syncVersion —— 每 10s 轮询 /ext/version，变化时拉 /ext/changes
 *  L3 滚动对账：reconcile —— 每 6h 全量分页对比修复漂移
 *
 *  独立通道：syncOnline（30s）/ syncFlow（5min 分片）
 *
 *  关键边界：/ext/version 回退（进程重启、内存态重置）→ 重置游标并全量对账
 */
class SyncEngine
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    // ------------------------------------------------------------------
    // L2：版本号探测 + 增量应用
    // ------------------------------------------------------------------

    /**
     * @return string 执行结果描述（供 Job 日志）
     */
    public function syncVersion(NpsServer $server): string
    {
        $api = $this->clientFactory->for($server);
        $resp = $api->extVersion()->orFail('ext/version');

        $data = (array) $resp->data;
        $remoteSeq = (int) ($data['change_seq'] ?? 0);
        $server->forceFill([
            'nps_version' => (string) ($data['version'] ?? ''),
            'ext_version' => (string) ($data['ext_version'] ?? ''),
            'change_seq' => $remoteSeq,
            'is_reachable' => true,
        ])->saveQuietly();

        // 版本回退：nps 进程重启，内存变更日志重置 → 全量对账（DESIGN.md §5.2）
        if ($remoteSeq < $server->last_synced_seq) {
            $previousSeq = $server->last_synced_seq;
            $this->reconcile($server);
            $server->forceFill([
                'last_synced_seq' => $remoteSeq,
                'last_synced_at' => now(),
            ])->saveQuietly();

            return sprintf('version rollback detected (%d < %d), full reconcile done', $remoteSeq, $previousSeq);
        }

        if ($remoteSeq > $server->last_synced_seq) {
            $applied = $this->syncChanges($server, $api, $server->last_synced_seq);
            $server->forceFill([
                'last_synced_seq' => $remoteSeq,
                'last_synced_at' => now(),
            ])->saveQuietly();

            return sprintf('%d changes applied (seq %d -> %d)', $applied, $server->last_synced_seq, $remoteSeq);
        }

        return 'no changes';
    }

    /**
     * 拉取并应用增量变更。truncated 时退化为全量对账。
     *
     * @return int 应用的变更条数
     */
    public function syncChanges(NpsServer $server, NpsApiClient $api, int $since): int
    {
        $resp = $api->extChanges($since)->orFail('ext/changes');
        $data = (array) $resp->data;

        if (! empty($data['truncated'])) {
            // 环形日志回绕：请求的 seq 早于日志起点 → 全量对账
            $this->reconcile($server);

            return (int) ($data['version'] ?? 0) - $since;
        }

        $changes = (array) ($data['changes'] ?? []);
        if (empty($changes)) {
            return 0;
        }

        // 按 type 分组：del 精确删除；add/update 拉详情 upsert；
        // 变更量大（>50）时退化为该 type 的全量分页刷新，避免 N 次单条请求
        // 注意：changelog 的 type 用 nps 内部对象名（client/task/host），
        // 与 /ext/list 的 type（client/tunnel/host）做规范化对齐
        $typeMap = ['client' => 'client', 'task' => 'tunnel', 'host' => 'host'];
        $byType = [];
        foreach ($changes as $change) {
            $rawType = (string) ($change['type'] ?? '');
            $type = $typeMap[$rawType] ?? null;
            if ($type === null) {
                Log::warning('nps sync: unknown change type, skipped', ['change' => $change]);
                continue;
            }
            $byType[$type][$change['op']][] = (int) $change['id'];
        }

        foreach ($byType as $type => $ops) {
            $dels = $ops['del'] ?? [];
            $upserts = array_merge($ops['add'] ?? [], $ops['update'] ?? []);

            if (! empty($dels)) {
                $this->deleteByRemoteIds($server->id, $type, $dels);
            }

            if (! empty($upserts)) {
                if (count($upserts) > 50) {
                    $this->refreshType($server, $api, $type);
                } else {
                    $this->upsertByIds($server, $api, $type, $upserts);
                }
            }
        }

        return count($changes);
    }

    // ------------------------------------------------------------------
    // 独立通道：在线状态 / 流量
    // ------------------------------------------------------------------

    public function syncOnline(NpsServer $server): string
    {
        $api = $this->clientFactory->for($server);
        $resp = $api->extOnline()->orFail('ext/online');
        $data = (array) $resp->data;
        $onlineIds = array_values(array_unique(array_map('intval', (array) ($data['online'] ?? []))));
        $onlineSet = array_flip($onlineIds);

        // 只对比“本地在线集合”与“远端在线集合”（AUDIT P2-2），
        // 避免每 30s 全量扫描该服务器的所有 client 镜像。
        $localOnline = NpsClient::where('server_id', $server->id)
            ->where('is_connect', true)
            ->pluck('remote_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toOnline = array_values(array_diff($onlineIds, $localOnline));
        $toOffline = array_values(array_diff($localOnline, $onlineIds));

        $changed = 0;
        foreach (array_chunk($toOnline, 5000) as $chunk) {
            if (! empty($chunk)) {
                $changed += NpsClient::where('server_id', $server->id)
                    ->whereIn('remote_id', $chunk)
                    ->update(['is_connect' => true]);
            }
        }
        foreach (array_chunk($toOffline, 5000) as $chunk) {
            if (! empty($chunk)) {
                $changed += NpsClient::where('server_id', $server->id)
                    ->whereIn('remote_id', $chunk)
                    ->update(['is_connect' => false]);
            }
        }

        return sprintf('online=%d changed=%d', count($onlineSet), $changed);
    }

    public function syncFlow(NpsServer $server, int $shard, int $total): string
    {
        $api = $this->clientFactory->for($server);
        $resp = $api->extFlow($shard, $total)->orFail('ext/flow');
        $data = (array) $resp->data;
        $flows = (array) ($data['flows'] ?? []);

        $updated = 0;
        foreach (array_chunk($flows, 200) as $batch) {
            $updated += $this->bulkUpdateFlows($server->id, $batch);
        }

        // P2 计费链路（DESIGN.md §8.3）：增量累加月度用量 + 超额执行
        $affectedUserIds = [];
        try {
            $billing = app(\App\Services\Portal\BillingService::class);
            $billing->accumulateFlow($server->id, $flows);
            // 收集本片涉及的归属用户，逐个做超额检查
            $clientIds = array_map(fn ($f) => (int) ($f['client_id'] ?? 0), $flows);
            if (! empty($clientIds)) {
                $affectedUserIds = NpsClient::where('server_id', $server->id)
                    ->whereIn('remote_id', $clientIds)
                    ->whereNotNull('user_id')
                    ->distinct()->pluck('user_id')->all();
            }
            foreach ($affectedUserIds as $userId) {
                $user = \App\Models\User::find($userId);
                if ($user) {
                    $billing->enforceQuota($user);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("billing accumulate failed for server {$server->id}: {$e->getMessage()}");
        }

        return sprintf('shard=%d/%d flows=%d updated=%d billed_users=%d', $shard, $total, count($flows), $updated, count($affectedUserIds));
    }

    // ------------------------------------------------------------------
    // L3：全量对账（游标分页 upsert + 删除失配）
    // ------------------------------------------------------------------

    public function reconcile(NpsServer $server): void
    {
        $api = $this->clientFactory->for($server);
        foreach (['client', 'tunnel', 'host'] as $type) {
            $this->refreshType($server, $api, $type);
        }
        // 对账完成即视为已同步到当前时刻：回写游标，避免后续增量重复拉取
        $resp = $api->extVersion();
        if ($resp->ok) {
            $data = (array) $resp->data;
            $server->forceFill([
                'nps_version' => (string) ($data['version'] ?? $server->nps_version),
                'ext_version' => (string) ($data['ext_version'] ?? $server->ext_version),
                'change_seq' => (int) ($data['change_seq'] ?? $server->change_seq),
                'last_synced_seq' => (int) ($data['change_seq'] ?? $server->change_seq),
                'last_synced_at' => now(),
                'last_reconciled_at' => now(),
                'is_reachable' => true,
            ])->saveQuietly();
        } else {
            $server->forceFill(['last_reconciled_at' => now()])->saveQuietly();
        }
    }

    /**
     * 全量分页刷新一个 type：upsert 全部远端对象 + 删除本地失配行
     */
    public function refreshType(NpsServer $server, NpsApiClient $api, string $type): void
    {
        $pageSize = (int) config('nps.reconcile_page_size');
        $cursor = 0;
        $seenIds = [];
        $total = null;

        do {
            $resp = $api->extList($type, $cursor, $pageSize)->orFail("ext/list {$type} cursor={$cursor}");
            $data = (array) $resp->data;
            $items = (array) ($data['items'] ?? []);
            $total = (int) ($data['total'] ?? 0);

            if (! empty($items)) {
                $this->upsertItems($server, $type, $items);
                foreach ($items as $item) {
                    $seenIds[] = (int) $item['id'];
                }
            }
            $cursor = (int) ($data['next_cursor'] ?? 0);
        } while ($cursor > 0 && count($seenIds) < $total);

        // 删除本地失配行（远端已不存在）。
        // 远端为空时也必须清理（AUDIT P2-6），但保留配置开关防误删。
        if ($total === 0) {
            if (config('nps.reconcile_delete_on_empty', true)) {
                $this->deleteAll($server->id, $type);
            } else {
                Log::warning("nps reconcile: remote {$type} is empty, skip local purge (guard disabled)");
            }
        } elseif (! empty($seenIds)) {
            $this->deleteMissing($server->id, $type, $seenIds);
        }
    }

    // ------------------------------------------------------------------
    // 内部：upsert / 删除
    // ------------------------------------------------------------------

    /**
     * 按 remote_id 拉取单条详情并 upsert（少量变更场景）
     */
    private function upsertByIds(NpsServer $server, NpsApiClient $api, string $type, array $remoteIds): void
    {
        $items = [];
        foreach ($remoteIds as $id) {
            // 复用 /ext/list 的游标语义：cursor = id-1, limit = 1 精确取单条
            $resp = $api->extList($type, $id - 1, 1);
            if ($resp->ok) {
                $data = (array) $resp->data;
                $rows = (array) ($data['items'] ?? []);
                if (! empty($rows) && (int) $rows[0]['id'] === $id) {
                    $items[] = $rows[0];
                }
            }
        }
        if (! empty($items)) {
            $this->upsertItems($server, $type, $items);
        }
    }

    private function upsertItems(NpsServer $server, string $type, array $items): void
    {
        $chunk = (int) config('nps.upsert_chunk');
        foreach (array_chunk($items, $chunk) as $batch) {
            match ($type) {
                'client' => $this->upsertClients($server, $batch),
                'tunnel' => $this->upsertTunnels($server, $batch),
                'host' => $this->upsertHosts($server, $batch),
                default => Log::warning("nps sync: unknown upsert type {$type}, skipped"),
            };
        }
    }

    private function upsertClients(NpsServer $server, array $items): void
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'server_id' => $server->id,
                'remote_id' => (int) $item['id'],
                'vkey' => (string) ($item['verify_key'] ?? ''),
                'remark' => (string) ($item['remark'] ?? ''),
                'status' => (bool) ($item['status'] ?? false),
                'is_connect' => (bool) ($item['is_connect'] ?? false),
                'addr' => (string) ($item['addr'] ?? ''),
                'npc_version' => $item['npc_version'] ?? null,
                'rate_limit' => (int) ($item['rate_limit'] ?? 0),
                'max_conn' => (int) ($item['max_conn'] ?? 0),
                'max_tunnel' => (int) ($item['max_tunnel'] ?? 0),
                'flow_limit' => (int) ($item['flow_limit'] ?? 0),
                'flow_in' => (int) ($item['flow_in'] ?? 0),
                'flow_out' => (int) ($item['flow_out'] ?? 0),
                'config_conn_allow' => (bool) ($item['config_conn_allow'] ?? false),
                'crypt' => (bool) ($item['crypt'] ?? false),
                'compress' => (bool) ($item['compress'] ?? false),
                'basic_user' => (string) ($item['basic_user'] ?? ''),
            ];
        }
        // upsert 不覆盖 is_connect（由 syncOnline 通道维护）与 user_id（本地归属）
        NpsClient::upsert(
            $rows,
            ['server_id', 'remote_id'],
            ['vkey', 'remark', 'status', 'addr', 'npc_version', 'rate_limit',
                'max_conn', 'max_tunnel', 'flow_limit', 'flow_in', 'flow_out',
                'config_conn_allow', 'crypt', 'compress', 'basic_user'],
        );
    }

    private function upsertTunnels(NpsServer $server, array $items): void
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'server_id' => $server->id,
                'remote_id' => (int) $item['id'],
                'client_remote_id' => (int) ($item['client_id'] ?? 0),
                'port' => (int) ($item['port'] ?? 0),
                'mode' => (string) ($item['mode'] ?? 'tcp'),
                'status' => (bool) ($item['status'] ?? false),
                'run_status' => (bool) ($item['run_status'] ?? false),
                'remark' => (string) ($item['remark'] ?? ''),
                'target' => (string) ($item['target'] ?? ''),
                'local_proxy' => (bool) ($item['local_proxy'] ?? false),
                'flow_in' => (int) ($item['flow_in'] ?? 0),
                'flow_out' => (int) ($item['flow_out'] ?? 0),
            ];
        }
        NpsTunnel::upsert(
            $rows,
            ['server_id', 'remote_id'],
            ['client_remote_id', 'port', 'mode', 'status', 'run_status',
                'remark', 'target', 'local_proxy', 'flow_in', 'flow_out'],
        );
        // 修复本地镜像外键：tunnel.client_id → nps_clients.id
        $this->repairClientRefs($server, 'nps_tunnels');
    }

    private function upsertHosts(NpsServer $server, array $items): void
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'server_id' => $server->id,
                'remote_id' => (int) $item['id'],
                'client_remote_id' => (int) ($item['client_id'] ?? 0),
                'host' => (string) ($item['host'] ?? ''),
                'location' => (string) ($item['location'] ?? '/'),
                'scheme' => (string) ($item['scheme'] ?? 'all'),
                'remark' => (string) ($item['remark'] ?? ''),
                'is_close' => (bool) ($item['is_close'] ?? false),
                'target' => (string) ($item['target'] ?? ''),
                'flow_in' => (int) ($item['flow_in'] ?? 0),
                'flow_out' => (int) ($item['flow_out'] ?? 0),
            ];
        }
        NpsHost::upsert(
            $rows,
            ['server_id', 'remote_id'],
            ['client_remote_id', 'host', 'location', 'scheme', 'remark',
                'is_close', 'target', 'flow_in', 'flow_out'],
        );
        $this->repairClientRefs($server, 'nps_hosts');
    }

    /**
     * 修复镜像外键：{table}.client_id = (SELECT id FROM nps_clients
     * WHERE server_id = ? AND remote_id = {table}.client_remote_id)
     */
    private function repairClientRefs(NpsServer $server, string $table): void
    {
        DB::update(
            "UPDATE {$table} SET client_id = (
                SELECT id FROM nps_clients
                WHERE nps_clients.server_id = ? AND nps_clients.remote_id = {$table}.client_remote_id
            ) WHERE {$table}.server_id = ?",
            [$server->id, $server->id],
        );
    }

    /**
     * 批量更新一片流量的 flow_in/flow_out（AUDIT P2-1）：
     * 每 200 行一条 CASE 更新，避免逐 client 两次查询。
     */
    private function bulkUpdateFlows(int $serverId, array $flows): int
    {
        $rows = [];
        foreach ($flows as $flow) {
            $remoteId = (int) ($flow['client_id'] ?? 0);
            if ($remoteId <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $remoteId,
                'in' => (int) ($flow['in'] ?? 0),
                'out' => (int) ($flow['out'] ?? 0),
            ];
        }
        if (empty($rows)) {
            return 0;
        }

        $ids = array_column($rows, 'id');
        $inCase = [];
        $outCase = [];
        $bindings = [];
        foreach ($rows as $row) {
            $inCase[] = 'WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['in'];
        }
        foreach ($rows as $row) {
            $outCase[] = 'WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['out'];
        }
        $bindings[] = $serverId;
        foreach ($ids as $id) {
            $bindings[] = $id;
        }

        $sql = 'UPDATE nps_clients SET '
            .'flow_in = CASE remote_id '.implode(' ', $inCase).' ELSE flow_in END, '
            .'flow_out = CASE remote_id '.implode(' ', $outCase).' ELSE flow_out END '
            .'WHERE server_id = ? AND remote_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';

        return DB::update($sql, $bindings);
    }

    private function deleteByRemoteIds(int $serverId, string $type, array $remoteIds): void
    {
        $ids = array_map('intval', $remoteIds);
        match ($type) {
            'client' => NpsClient::where('server_id', $serverId)->whereIn('remote_id', $ids)->delete(),
            'tunnel' => NpsTunnel::where('server_id', $serverId)->whereIn('remote_id', $ids)->delete(),
            'host' => NpsHost::where('server_id', $serverId)->whereIn('remote_id', $ids)->delete(),
            default => Log::warning("nps sync: unknown delete type {$type}, skipped"),
        };
    }

    private function deleteAll(int $serverId, string $type): void
    {
        $model = match ($type) {
            'client' => NpsClient::class,
            'tunnel' => NpsTunnel::class,
            'host' => NpsHost::class,
            default => null,
        };
        if ($model) {
            $model::where('server_id', $serverId)->delete();
        }
    }

    /**
     * 删除本地有而远端没有的行（分批 NOT IN，避免超大 IN 子句）
     */
    private function deleteMissing(int $serverId, string $type, array $seenIds): void
    {
        $seen = array_flip(array_map('intval', $seenIds));
        $model = match ($type) {
            'client' => NpsClient::class,
            'tunnel' => NpsTunnel::class,
            'host' => NpsHost::class,
        };

        $model::where('server_id', $serverId)->select('id', 'remote_id')
            ->chunkById(5000, function ($rows) use ($model, $seen) {
                $toDelete = [];
                foreach ($rows as $row) {
                    if (! isset($seen[(int) $row->remote_id])) {
                        $toDelete[] = $row->id;
                    }
                }
                if (! empty($toDelete)) {
                    $model::whereIn('id', $toDelete)->delete();
                }
            });
    }
}
