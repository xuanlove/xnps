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
 * L2 版本号轮询 Job（DESIGN.md §6.1：每 10s）
 *
 * 一次性任务：由 schedule:work 每 10 秒派发（routes/console.php），
 * 不自循环。ShouldBeUnique 保证同一服务器不会因调度叠加/队列积压而堆积。
 */
class SyncVersionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** 唯一锁兜底 TTL（秒），避免意外中断后永久阻塞派发 */
    public int $uniqueFor = 60;

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
            return; // 服务器被移除/停用
        }

        try {
            $result = $syncEngine->syncVersion($server);
            Log::debug("nps sync [{$server->name}] {$result}");
        } catch (\Throwable $e) {
            // 单次失败不影响下一拍；is_reachable 已在 SDK 内更新
            Log::warning("nps sync [{$server->name}] failed: {$e->getMessage()}");
        }
    }
}
