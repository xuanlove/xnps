<?php

namespace App\Jobs;

use App\Models\NpsServer;
use App\Services\Sync\SyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 流量采集 Job（DESIGN.md §6.1：每 5min 一片，16 分片轮转）
 *
 * 分片号由时间取模推导（全局同步轮转），无需持久化状态
 */
class SyncFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function handle(SyncEngine $syncEngine): void
    {
        $server = NpsServer::find($this->serverId);
        if (! $server || ! $server->isActive()) {
            return;
        }

        $total = (int) config('nps.flow_shards');
        // 全局轮转分片：所有服务器同一时刻采同一片，错峰由 dispatch jitter 承担
        $shard = (int) floor(time() / (int) config('nps.flow_interval')) % $total;

        try {
            $result = $syncEngine->syncFlow($server, $shard, $total);
            Log::debug("nps flow [{$server->name}] {$result}");
        } catch (\Throwable $e) {
            Log::warning("nps flow [{$server->name}] failed: {$e->getMessage()}");
        }

        static::dispatch($this->serverId)
            ->delay(now()->addSeconds((int) config('nps.flow_interval')))
            ->onQueue('nps-sync');
    }
}
