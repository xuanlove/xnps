<?php

use App\Jobs\CollectMetricsJob;
use App\Jobs\ReconcileServerJob;
use App\Jobs\SyncFlowJob;
use App\Jobs\SyncOnlineJob;
use App\Jobs\SyncVersionJob;
use App\Models\NpsApiLog;
use App\Models\NpsServer;
use Illuminate\Support\Facades\Schedule;

// ---- nps 集中管理调度（DESIGN.md §6 / §12.2）----
//
// 秒级任务由 schedule:work 进程按节拍派发一次性 Job（不再自循环，
// 避免 seeds 每 10 分钟叠加出新的永久循环）。Job 实现 ShouldBeUnique，
// 队列积压时重复派发会被自动丢弃。生产推荐常驻 `php artisan schedule:work`；
// cron `schedule:run` 也会在分钟内重复到期事件，但需自行防进程重叠。

Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        SyncVersionJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everyTenSeconds()->name('nps-sync-version')->withoutOverlapping(1);

Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        SyncOnlineJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everyThirtySeconds()->name('nps-sync-online')->withoutOverlapping(1);

Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        SyncFlowJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everyFiveMinutes()->name('nps-sync-flow')->withoutOverlapping(5);

Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        CollectMetricsJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everyThirtySeconds()->name('nps-metrics')->withoutOverlapping(1);

// L3 滚动对账：每 6h 全量兜底
Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        ReconcileServerJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everySixHours()->name('nps-reconcile');

// 审计日志清理（保留天数见 config/nps.php）
Schedule::call(function () {
    NpsApiLog::where('created_at', '<', now()->subDays((int) config('nps.audit_retention_days')))->delete();
})->daily()->name('nps-audit-cleanup');

// ---- P2 门户（DESIGN.md §8）----

// 审批过期扫描：submitted 超时自动 expired
Schedule::call(function () {
    app(\App\Services\Portal\ApprovalService::class)->expireStale();
})->hourly()->name('approval-expire-scan');

// 月度结算：每月 1 号 00:05 结算上月（DESIGN.md §8.3）
Schedule::call(function () {
    app(\App\Services\Portal\BillingService::class)->settleAll();
})->monthlyOn(1, '00:05')->name('monthly-settlement');

// ---- 证书与监控（DESIGN.md §7 / 阶段 3）----

// 告警检查：阈值 / 不可达 / 证书到期 / 同步延迟 / 隧道异常
Schedule::call(function () {
    app(\App\Services\Monitor\MonitoringService::class)->checkAll();
})->everyFiveMinutes()->name('alert-check');

// 支付订单过期回收（未支付超时置 expired）
Schedule::call(function () {
    app(\App\Services\Payments\PaymentService::class)->expirePending();
})->everyFiveMinutes()->name('payment-expire');

// 证书指纹全平台对账（DESIGN.md §7.2 双 SoT 防护）
Schedule::call(function () {
    app(\App\Services\Cert\CertificateService::class)->reconcileAll();
})->dailyAt('03:00')->name('cert-reconcile');

// 监控采样清理（保留天数）
Schedule::call(function () {
    app(\App\Services\Monitor\MonitoringService::class)->pruneMetrics();
})->daily()->name('metrics-prune');
