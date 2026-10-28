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
 * 在线状态轮询 Job（DESIGN.md §6.1：每 30s，/ext/online）
 * 一次性任务，由 schedule:work 派发；ShouldBeUnique 防叠加。
 */
class SyncOnlineJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 90;

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
            $result = $syncEngine->syncOnline($server);
            Log::debug("nps online [{$server->name}] {$result}");
        } catch (\Throwable $e) {
            Log::warning("nps online [{$server->name}] failed: {$e->getMessage()}");
        }
    }
}
