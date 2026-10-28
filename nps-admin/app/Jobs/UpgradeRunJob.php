<?php

namespace App\Jobs;

use App\Models\UpgradeRun;
use App\Services\Upgrade\UpgradeOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 升级编排 Job（DESIGN.md §9.3）：异步执行一次 run（canary + 滚动批次）
 * 超时按服务器数 × 单台上限（升级+验证+回滚最坏 ~5 分钟/台）
 */
class UpgradeRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function timeout(): int
    {
        $run = UpgradeRun::find($this->runId);

        return 300 + (count($run?->server_ids ?? []) * 300);
    }

    public function handle(UpgradeOrchestrator $orchestrator): void
    {
        $run = UpgradeRun::with('artifact')->find($this->runId);
        if (! $run || $run->status !== UpgradeRun::STATUS_RUNNING) {
            return;
        }

        try {
            $finished = $orchestrator->execute(
                $run,
                $run->artifact,
                $run->server_ids,
                $run->canary_server_id,
                (int) $run->observe_seconds,
            );
            Log::info("upgrade run {$run->id} finished: {$finished->status}", ['summary' => $finished->summary]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => UpgradeRun::STATUS_FAILED,
                'finished_at' => now(),
                'summary' => ['error' => $e->getMessage()],
            ]);
            Log::error("upgrade run {$run->id} failed: {$e->getMessage()}");
        }
    }
}
