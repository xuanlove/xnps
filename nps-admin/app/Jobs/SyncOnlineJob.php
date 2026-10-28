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
 * 在线状态轮询 Job（DESIGN.md §6.1：每 30s，/ext/online）
 */
class SyncOnlineJob implements ShouldQueue
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

        try {
            $result = $syncEngine->syncOnline($server);
            Log::debug("nps online [{$server->name}] {$result}");
        } catch (\Throwable $e) {
            Log::warning("nps online [{$server->name}] failed: {$e->getMessage()}");
        }

        static::dispatch($this->serverId)
            ->delay(now()->addSeconds((int) config('nps.sync_online_interval')))
            ->onQueue('nps-sync');
    }
}
