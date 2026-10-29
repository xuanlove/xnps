<?php

namespace App\Jobs;

use App\Models\NpsServer;
use App\Services\Sync\SyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 流量采集 Job（DESIGN.md §6.1：每 5min 一片，16 分片轮转）
 *
 * 分片号由时间取模推导（全局同步轮转），无需持久化状态。
 * 一次性任务，由 schedule:work 派发；ShouldBeUnique 防叠加。
 */
class SyncFlowJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(SyncEngine $syncEngine): void
    {
        Log::withContext(['server_id' => $this->serverId, 'job' => static::class]);

        $server = NpsServer::find($this->serverId);
        if (! $server || ! $server->isActive()) {
            return;
        }

        $total = (int) config('nps.flow_shards');
        // 全局轮转分片：所有服务器同一时刻采同一片，错峰由 dispatch jitter 承担
        $shard = (int) floor(time() / (int) config('nps.flow_interval')) % $total;

        // 防重叠执行（AUDIT M2）：唯一锁 TTL 到期或手工派发时，
        // 缓存锁保证同一服务器不会并发写计费基线（基线必须串行采样）
        $interval = (int) config('nps.flow_interval');
        $lock = Cache::lock("nps-flow:{$this->serverId}", max(60, $interval - 60));
        try {
            if ($lock->get()) {
                try {
                    $result = $syncEngine->syncFlow($server, $shard, $total);
                    Log::debug("nps flow [{$server->name}] {$result}");
                } finally {
                    $lock->release();
                }
            } else {
                Log::debug("nps flow [{$server->name}] skipped: previous run still holds the lock");
            }
        } catch (\Throwable $e) {
            Log::warning("nps flow [{$server->name}] failed: {$e->getMessage()}");
        }
    }
}
