<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Bill;
use App\Models\NpsApiLog;
use App\Models\NpsServer;
use App\Models\UpgradeRun;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Prometheus 指标端点（I3，text exposition format 0.0.4）。
 *
 * 覆盖：服务器可达性/同步延迟、队列积压、API 错误率（窗口）、
 * 计费结算耗时、升级 Run 状态、告警数量。
 * 可选保护：NPS_METRICS_TOKEN 非空时要求 Bearer 或 X-Metrics-Token。
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('nps.metrics_token');
        if ($token !== '') {
            $given = (string) ($request->bearerToken() ?: $request->header('X-Metrics-Token', ''));
            if (! hash_equals($token, $given)) {
                return response('forbidden', 403, ['Content-Type' => 'text/plain']);
            }
        }

        $lines = [];
        $this->exposeServers($lines);
        $this->exposeQueue($lines);
        $this->exposeApiLogs($lines);
        $this->exposeBilling($lines);
        $this->exposeUpgrades($lines);
        $this->exposeAlerts($lines);

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------------------------------

    private function exposeServers(array &$lines): void
    {
        $this->header($lines, 'nps_server_up', 'gauge', 'nps server reachable (1) or not (0)');
        $this->header($lines, 'nps_sync_lag_seconds', 'gauge', 'seconds since last successful sync (-1 = never)');
        $this->header($lines, 'nps_server_clients_online', 'gauge', 'online clients mirrored locally');

        foreach (NpsServer::where('status', 'active')->get() as $server) {
            $labels = ['server' => $server->name];
            $this->sample($lines, 'nps_server_up', $labels, $server->is_reachable ? 1 : 0);
            $lag = $server->last_synced_at ? max(0, $server->last_synced_at->diffInSeconds(now())) : -1;
            $this->sample($lines, 'nps_sync_lag_seconds', $labels, $lag);
            $this->sample($lines, 'nps_server_clients_online', $labels,
                (int) $server->clients()->where('is_connect', true)->count());
        }
    }

    private function exposeQueue(array &$lines): void
    {
        $this->header($lines, 'nps_queue_jobs', 'gauge', 'pending jobs per queue');
        foreach ((array) config('nps.queues', ['default']) as $queue) {
            try {
                $size = Queue::size($queue);
            } catch (\Throwable $e) {
                Log::warning("metrics: queue size failed for {$queue}: {$e->getMessage()}");
                $size = -1;
            }
            $this->sample($lines, 'nps_queue_jobs', ['queue' => $queue], $size);
        }

        $failed = 0;
        try {
            $failed = (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        } catch (\Throwable $e) {
            Log::warning('metrics: failed_jobs count failed: '.$e->getMessage());
        }
        $this->header($lines, 'nps_queue_failed_jobs_total', 'gauge', 'rows in failed_jobs');
        $this->sample($lines, 'nps_queue_failed_jobs_total', [], $failed);
    }

    private function exposeApiLogs(array &$lines): void
    {
        $window = (int) config('nps.metrics_api_window_minutes', 15);
        $since = now()->subMinutes($window);
        $base = NpsApiLog::where('created_at', '>=', $since);

        $total = (clone $base)->count();
        $errors = (clone $base)->where('ok', false)->count();
        $ratio = $total > 0 ? $errors / $total : 0.0;

        $this->header($lines, 'nps_api_requests_window', 'gauge', 'nps API calls in recent window');
        $this->header($lines, 'nps_api_errors_window', 'gauge', 'failed nps API calls in recent window');
        $this->header($lines, 'nps_api_error_ratio', 'gauge', 'error ratio in recent window (0-1)');
        $labels = ['window' => $window.'m'];
        $this->sample($lines, 'nps_api_requests_window', $labels, $total);
        $this->sample($lines, 'nps_api_errors_window', $labels, $errors);
        $this->sample($lines, 'nps_api_error_ratio', $labels, round($ratio, 4));
    }

    private function exposeBilling(array &$lines): void
    {
        $rows = Bill::whereNotNull('settle_duration_ms')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('AVG(settle_duration_ms) as avg_ms, MAX(settle_duration_ms) as max_ms, COUNT(*) as n')
            ->first();

        $this->header($lines, 'nps_billing_settle_duration_seconds', 'gauge', 'monthly bill settlement duration (last 24h)');
        $this->sample($lines, 'nps_billing_settle_duration_seconds', ['stat' => 'avg'],
            round(((float) ($rows->avg_ms ?? 0)) / 1000, 4));
        $this->sample($lines, 'nps_billing_settle_duration_seconds', ['stat' => 'max'],
            round(((float) ($rows->max_ms ?? 0)) / 1000, 4));
        $this->header($lines, 'nps_billing_settled_total', 'gauge', 'bills settled in last 24h');
        $this->sample($lines, 'nps_billing_settled_total', [], (int) ($rows->n ?? 0));
    }

    private function exposeUpgrades(array &$lines): void
    {
        $this->header($lines, 'nps_upgrade_runs', 'gauge', 'upgrade runs by status');
        $counts = UpgradeRun::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        foreach (['running', 'succeeded', 'failed', 'aborted'] as $status) {
            $this->sample($lines, 'nps_upgrade_runs', ['status' => $status], (int) ($counts[$status] ?? 0));
        }
    }

    private function exposeAlerts(array &$lines): void
    {
        $this->header($lines, 'nps_alerts_open', 'gauge', 'open alerts by level');
        $counts = Alert::where('status', Alert::STATUS_OPEN)
            ->selectRaw('level, COUNT(*) as c')->groupBy('level')->pluck('c', 'level');
        foreach (['info', 'warning', 'critical'] as $level) {
            $this->sample($lines, 'nps_alerts_open', ['level' => $level], (int) ($counts[$level] ?? 0));
        }
    }

    // ------------------------------------------------------------------

    private function header(array &$lines, string $name, string $type, string $help): void
    {
        $lines[] = "# HELP {$name} {$help}";
        $lines[] = "# TYPE {$name} {$type}";
    }

    private function sample(array &$lines, string $name, array $labels, int|float $value): void
    {
        $suffix = '';
        if ($labels !== []) {
            $pairs = [];
            foreach ($labels as $k => $v) {
                $pairs[] = $k.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v).'"';
            }
            $suffix = '{'.implode(',', $pairs).'}';
        }
        $lines[] = $name.$suffix.' '.$value;
    }
}
