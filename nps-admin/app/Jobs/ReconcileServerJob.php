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
use Illuminate\Support\Facades\Log;

/**
 * L3 滚动对账 Job（DESIGN.md §6.1：每 6h 兜底）
 *
 * 一次性任务；ShouldBeUnique 防止手工触发与定时任务叠加同一台服务器。
 */
class ReconcileServerJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

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
