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
 * L2 版本号轮询 Job（DESIGN.md §6.1：每 10s）
 *
 * 自循环调度：完成后 delay 重新入队，保证秒级精度（cron 只有分钟级）。
 * routes/console.php 的 scheduler 每 10 分钟播一颗种子防循环断裂（幂等）。
 */
class SyncVersionJob implements ShouldQueue
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
            return; // 服务器被移除/停用：循环自然终止
        }

        try {
            $result = $syncEngine->syncVersion($server);
            Log::debug("nps sync [{$server->name}] {$result}");
        } catch (\Throwable $e) {
            // 单次失败不中断循环；is_reachable 已在 SDK 内更新
            Log::warning("nps sync [{$server->name}] failed: {$e->getMessage()}");
        }

        // 自循环：10s 后重新入队
        static::dispatch($this->serverId)
            ->delay(now()->addSeconds((int) config('nps.sync_version_interval')))
            ->onQueue('nps-sync');
    }
}
