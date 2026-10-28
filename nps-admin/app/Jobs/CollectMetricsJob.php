<?php

namespace App\Jobs;

use App\Models\NpsServer;
use App\Services\Monitor\MonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 监控采集 Job（阶段 3）：每 30s 采一台服务器的 /ext/system 落库
 * 一次性任务，由 schedule:work 派发；ShouldBeUnique 防叠加。
 */
class CollectMetricsJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(MonitoringService $monitoring): void
    {
        $server = NpsServer::find($this->serverId);
        if (! $server || ! $server->isActive()) {
            return;
        }

        $monitoring->collectMetrics($server);
    }
}
