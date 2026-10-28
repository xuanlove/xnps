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
 * L3 滚动对账 Job（DESIGN.md §6.1：每 6h 兜底）
 *
 * 由 scheduler 分钟级调度（routes/console.php），无需自循环
 */
class ReconcileServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function handle(SyncEngine $syncEngine): void
    {
        $server = NpsServer::find($this->serverId);
        if (! $server || ! $server->isActive()) {
            return;
        }

        try {
            $syncEngine->reconcile($server);
            Log::info("nps reconcile [{$server->name}] done");
        } catch (\Throwable $e) {
            Log::error("nps reconcile [{$server->name}] failed: {$e->getMessage()}");
        }
    }
}
