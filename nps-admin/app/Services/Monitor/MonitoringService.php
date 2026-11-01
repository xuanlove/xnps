<?php

namespace App\Services\Monitor;

use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertSilence;
use App\Models\NpsServer;
use App\Models\ServerMetric;
use App\Services\Nps\NpsApiClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * 监控与告警（DESIGN.md 阶段 3 / I3）：
 *  - collectMetrics：/ext/system 轮询采样落库（历史曲线由管理端自采）
 *  - checkAlerts：阈值 / 不可达 / 证书到期 / 同步延迟 / 隧道异常
 *  - 告警生命周期：去抖窗口可配置、静默匹配、条件恢复自动 resolve
 *  - 通知：告警落库 + 可排队重试的 webhook（SendAlertWebhook）
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
            'disk_used_percent' => $data['disk_used_percent'] ?? null,
            'tcp_established' => $data['tcp_established'] ?? null,
            'open_fds' => $data['open_fds'] ?? null,
            'client_online' => $data['client_online'] ?? 0,
            'goroutines' => $data['goroutines'] ?? null,
            'goroutines_peak' => $data['goroutines_peak'] ?? null,
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
     * 单台服务器检查：不可达 / CPU / 内存 / 磁盘 / 同步延迟 / 隧道异常。
     * 本轮未触发的服务器级告警自动 resolve（I3 条件恢复）。
     */
    public function checkServer(NpsServer $server): int
    {
        $count = 0;
        $fired = [];
        $thresholds = (array) config('nps.monitor.alerts');

        // 1. 不可达（连续失败才告警——is_reachable 由 SDK 每次调用更新）
        if (! $server->is_reachable) {
            $fired[] = 'unreachable';
            $count += $this->raise($server, 'unreachable', 'critical',
                "nps server {$server->name} unreachable", ['api_url' => $server->api_url]);
        }

        // 2/3. CPU / 内存 / 磁盘阈值
        $latest = ServerMetric::where('server_id', $server->id)
            ->orderByDesc('collected_at')->first();
        if ($latest) {
            if ($latest->cpu_percent !== null && $latest->cpu_percent >= (float) ($thresholds['cpu_percent'] ?? 90)) {
                $fired[] = 'cpu_high';
                $count += $this->raise($server, 'cpu_high', 'warning',
                    sprintf('cpu %.1f%% >= threshold on %s', $latest->cpu_percent, $server->name),
                    ['cpu_percent' => $latest->cpu_percent]);
            }
            if ($latest->mem_used_percent !== null && $latest->mem_used_percent >= (float) ($thresholds['mem_percent'] ?? 90)) {
                $fired[] = 'mem_high';
                $count += $this->raise($server, 'mem_high', 'warning',
                    sprintf('mem %.1f%% >= threshold on %s', $latest->mem_used_percent, $server->name),
                    ['mem_used_percent' => $latest->mem_used_percent]);
            }
            if ($latest->disk_used_percent !== null && $latest->disk_used_percent >= (float) ($thresholds['disk_percent'] ?? 90)) {
                $fired[] = 'disk_high';
                $count += $this->raise($server, 'disk_high', 'warning',
                    sprintf('disk %.1f%% >= threshold on %s', $latest->disk_used_percent, $server->name),
                    ['disk_used_percent' => $latest->disk_used_percent]);
            }
        }

        // 4. 同步延迟
        $lagMinutes = (int) config('nps.monitor.alerts.sync_lag_minutes', 5);
        if ($server->last_synced_at && $server->last_synced_at->lt(now()->subMinutes($lagMinutes))) {
            $fired[] = 'sync_lag';
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
            $fired[] = 'tunnel_down';
            $count += $this->raise($server, 'tunnel_down', 'warning',
                "{$downTunnels} tunnels enabled but not running on {$server->name}",
                ['count' => $downTunnels]);
        }

        $this->resolveCleared($server, $fired);

        return $count;
    }

    /**
     * 证书到期检查（DESIGN.md §7.2：到期前 30 天）。
     * 续期/更换后不再处于到期窗口的告警自动 resolve（I3）。
     */
    public function checkCertificates(): int
    {
        $count = 0;
        $expiringIds = [];
        $service = app(\App\Services\Cert\CertificateService::class);
        foreach ($service->expiringSoon() as $cert) {
            $expiringIds[] = (int) $cert->id;
            $days = $cert->daysUntilExpiry();
            $count += $this->raise(null, 'cert_expiring', $days <= 7 ? 'critical' : 'warning',
                "certificate '{$cert->name}' expires in {$days} days",
                ['certificate_id' => $cert->id, 'not_after' => $cert->not_after?->toIso8601String()]);
        }

        $open = Alert::whereNull('server_id')
            ->where('type', 'cert_expiring')
            ->whereIn('status', [Alert::STATUS_OPEN, Alert::STATUS_ACKNOWLEDGED])
            ->get();
        foreach ($open as $alert) {
            $certId = (int) ($alert->context['certificate_id'] ?? 0);
            if (! in_array($certId, $expiringIds, true)) {
                $alert->update(['status' => Alert::STATUS_RESOLVED, 'resolved_at' => now()]);
            }
        }

        return $count;
    }

    /**
     * 条件恢复：本轮未触发的服务器级告警置 resolved（I3）。
     * 证书告警由 checkCertificates 按到期窗口单独 resolve。
     */
    private function resolveCleared(NpsServer $server, array $fired): void
    {
        $query = Alert::where('server_id', $server->id)
            ->whereIn('type', Alert::SERVER_TYPES)
            ->whereIn('status', [Alert::STATUS_OPEN, Alert::STATUS_ACKNOWLEDGED]);
        if ($fired !== []) {
            $query->whereNotIn('type', $fired);
        }
        $resolved = $query->update([
            'status' => Alert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
        if ($resolved > 0) {
            Log::info("alerts auto-resolved on {$server->name}: {$resolved}");
        }
    }

    // ------------------------------------------------------------------

    /**
     * 告警去抖 + 静默 + 排队通知（I3）
     */
    private function raise(?NpsServer $server, string $type, string $level, string $message, array $context = []): int
    {
        if ($this->isSilenced($server, $type)) {
            Log::info("alert silenced: {$type} on ".($server?->name ?? 'platform'));

            return 0;
        }

        // 去抖：同 server+type 在窗口内且未 resolved 的告警不重复（窗口可配置）
        $debounce = (int) config('nps.monitor.alert_debounce_minutes', 60);
        $exists = Alert::where('server_id', $server?->id)
            ->where('type', $type)
            ->where('status', '!=', Alert::STATUS_RESOLVED)
            ->where('created_at', '>', now()->subMinutes($debounce))
            ->exists();
        if ($exists) {
            return 0;
        }

        $alert = Alert::create([
            'server_id' => $server?->id,
            'type' => $type,
            'level' => $level,
            'status' => Alert::STATUS_OPEN,
            'message' => $message,
            'context' => $context,
            'notified' => false,
            'created_at' => now(),
        ]);

        if ((string) config('nps.monitor.webhook_url') !== '') {
            SendAlertWebhook::dispatch($alert->id)->onQueue('nps-sync');
        }

        Log::warning("ALERT [{$level}] {$type}: {$message}");

        return 1;
    }

    /**
     * 静默匹配：server_id/type 为空的静默记录分别匹配全部服务器/全部类型。
     */
    private function isSilenced(?NpsServer $server, string $type): bool
    {
        return AlertSilence::where('until', '>', now())
            ->where(function ($q) use ($server) {
                $q->whereNull('server_id');
                if ($server !== null) {
                    $q->orWhere('server_id', $server->id);
                }
            })
            ->where(function ($q) use ($type) {
                $q->whereNull('type')->orWhere('type', $type);
            })
            ->exists();
    }
}
