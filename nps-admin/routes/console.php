<?php

use App\Jobs\ReconcileServerJob;
use App\Jobs\SyncFlowJob;
use App\Jobs\SyncOnlineJob;
use App\Jobs\SyncVersionJob;
use App\Models\NpsApiLog;
use App\Models\NpsServer;
use Illuminate\Support\Facades\Schedule;

// ---- nps 集中管理调度（DESIGN.md §6 / §12.2）----

// 秒级精度任务由 Job 自循环承担（SyncVersionJob 10s / SyncOnlineJob 30s /
// SyncFlowJob 5min）；此处每 10 分钟播一颗种子防循环断裂（幂等，重复执行无害）
Schedule::call(function () {
    NpsServer::where('status', 'active')->each(function (NpsServer $server) {
        SyncVersionJob::dispatch($server->id)->onQueue('nps-sync');
        SyncOnlineJob::dispatch($server->id)->onQueue('nps-sync');
        SyncFlowJob::dispatch($server->id)->onQueue('nps-sync');
    });
})->everyTenMinutes()->name('nps-sync-seeds');

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
