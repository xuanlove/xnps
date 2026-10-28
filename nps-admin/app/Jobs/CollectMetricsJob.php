<?php

namespace App\Jobs;

use App\Models\NpsServer;
use App\Services\Monitor\MonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 监控采集 Job（阶段 3）：每 30s 采一台服务器的 /ext/system 落库
 * 自循环调度（秒级精度），scheduler 每 10 分钟播种子防断裂
 */
class CollectMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function handle(MonitoringService $monitoring): void
    {
        $server = NpsServer::find($this->serverId);
        if (! $server || ! $server->isActive()) {
            return;
        }

        $monitoring->collectMetrics($server);

        static::dispatch($this->serverId)
            ->delay(now()->addSeconds((int) config('nps.monitor.interval', 30)))
            ->onQueue('nps-sync');
    }
}
