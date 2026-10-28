<?php

namespace App\Services\Monitor;

use App\Models\Alert;
use App\Models\Certificate;
use App\Models\NpsServer;
use App\Models\ServerMetric;
use App\Services\Nps\NpsApiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 监控与告警（DESIGN.md 阶段 3）：
 *  - collectMetrics：/ext/system 轮询采样落库（历史曲线由管理端自采）
 *  - checkAlerts：阈值 / 不可达 / 证书到期 / 同步延迟 / 隧道异常
 *  - 通知：告警落库 + 可选 webhook（config nps.monitor.webhook_url）
 */
class MonitoringService
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    // ------------------------------------------------------------------
    // 采集
    // ------------------------------------------------------------------

    /**
     * 采集一台服务器的系统指标（/ext/system，O(1) 端点）
     */
    public function collectMetrics(NpsServer $server): ?ServerMetric
    {
        $resp = $this->clientFactory->for($server)->extSystem();
        if (! $resp->ok) {
            Log::debug("metrics collect failed for {$server->name}: {$resp->msg}");

            return null;
        }
        $data = (array) $resp->data;

        return ServerMetric::create([
            'server_id' => $server->id,
            'cpu_percent' => $data['cpu_percent'] ?? null,
            'mem_used_percent' => $data['mem_used_percent'] ?? null,
            'load1' => $data['load1'] ?? null,
            'tcp_established' => $data['tcp_established'] ?? null,
            'client_online' => $data['client_online'] ?? 0,
            'goroutines' => $data['goroutines'] ?? null,
            'collected_at' => now(),
        ]);
    }

    /**
     * 清理过期采样（保留天数 config nps.monitor.metrics_retention_days）
     */
    public function pruneMetrics(): int
    {
        return ServerMetric::where('collected_at', '<',
            now()->subDays((int) config('nps.monitor.metrics_retention_days', 7)))->delete();
    }

    // ------------------------------------------------------------------
    // 告警
    // ------------------------------------------------------------------

    /**
     * 全量告警检查（scheduler 周期调用）
     *
     * @return int 新增告警条数
     */
    public function checkAll(): int
    {
        $count = 0;
        foreach (NpsServer::where('status', 'active')->get() as $server) {
            $count += $this->checkServer($server);
        }
        $count += $this->checkCertificates();

        return $count;
    }

    /**
     * 单台服务器检查：不可达 / CPU / 内存 / 同步延迟 / 隧道异常
     */
    public function checkServer(NpsServer $server): int
    {
        $count = 0;
        $thresholds = (array) config('nps.monitor.alerts');

        // 1. 不可达（连续失败才告警——is_reachable 由 SDK 每次调用更新）
        if (! $server->is_reachable) {
            $count += $this->raise($server, 'unreachable', 'critical',
                "nps server {$server->name} unreachable", ['api_url' => $server->api_url]);
        }

        // 2/3. CPU / 内存阈值
        $latest = ServerMetric::where('server_id', $server->id)
            ->orderByDesc('collected_at')->first();
        if ($latest) {
            if ($latest->cpu_percent !== null && $latest->cpu_percent >= (float) ($thresholds['cpu_percent'] ?? 90)) {
                $count += $this->raise($server, 'cpu_high', 'warning',
                    sprintf('cpu %.1f%% >= threshold on %s', $latest->cpu_percent, $server->name),
                    ['cpu_percent' => $latest->cpu_percent]);
            }
            if ($latest->mem_used_percent !== null && $latest->mem_used_percent >= (float) ($thresholds['mem_percent'] ?? 90)) {
                $count += $this->raise($server, 'mem_high', 'warning',
                    sprintf('mem %.1f%% >= threshold on %s', $latest->mem_used_percent, $server->name),
                    ['mem_used_percent' => $latest->mem_used_percent]);
            }
        }

        // 4. 同步延迟
        $lagMinutes = (int) config('nps.monitor.alerts.sync_lag_minutes', 5);
        if ($server->last_synced_at && $server->last_synced_at->lt(now()->subMinutes($lagMinutes))) {
            $count += $this->raise($server, 'sync_lag', 'warning',
                "sync lag > {$lagMinutes}min on {$server->name}",
                ['last_synced_at' => $server->last_synced_at?->toIso8601String()]);
        }

        // 5. 隧道异常：status=true 但 run_status=false（nps 虚报，DESIGN.md §2.4-3）
        $downTunnels = \App\Models\NpsTunnel::where('server_id', $server->id)
            ->where('status', true)->where('run_status', false)
            ->where('mode', '!=', 'secret')->where('mode', '!=', 'p2p')
            ->count();
        if ($downTunnels > 0) {
            $count += $this->raise($server, 'tunnel_down', 'warning',
                "{$downTunnels} tunnels enabled but not running on {$server->name}",
                ['count' => $downTunnels]);
        }

        return $count;
    }

    /**
     * 证书到期检查（DESIGN.md §7.2：到期前 30 天）
     */
    public function checkCertificates(): int
    {
        $count = 0;
        $service = app(\App\Services\Cert\CertificateService::class);
        foreach ($service->expiringSoon() as $cert) {
            $days = $cert->daysUntilExpiry();
            $count += $this->raise(null, 'cert_expiring', $days <= 7 ? 'critical' : 'warning',
                "certificate '{$cert->name}' expires in {$days} days",
                ['certificate_id' => $cert->id, 'not_after' => $cert->not_after?->toIso8601String()]);
        }

        return $count;
    }

    // ------------------------------------------------------------------

    /**
     * 告警去抖：同 server+type 1 小时内不重复
     */
    private function raise(?NpsServer $server, string $type, string $level, string $message, array $context = []): int
    {
        $exists = Alert::where('server_id', $server?->id)
            ->where('type', $type)
            ->where('created_at', '>', now()->subHour())
            ->exists();
        if ($exists) {
            return 0;
        }

        $alert = Alert::create([
            'server_id' => $server?->id,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);

        // 可选 webhook 通知
        $webhook = (string) config('nps.monitor.webhook_url');
        if ($webhook !== '') {
            try {
                $resp = Http::timeout(10)->post($webhook, [
                    'level' => $level,
                    'type' => $type,
                    'server' => $server?->name,
                    'message' => $message,
                    'context' => $context,
                    'at' => now()->toIso8601String(),
                ]);
                $alert->update(['notified' => $resp->successful()]);
            } catch (\Throwable $e) {
                Log::warning("alert webhook failed: {$e->getMessage()}");
            }
        }

        Log::warning("ALERT [{$level}] {$type}: {$message}");

        return 1;
    }
}
