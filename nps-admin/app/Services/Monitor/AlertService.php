<?php

namespace App\Services\Monitor;

use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertSilence;
use App\Models\NpsServer;
use Illuminate\Support\Facades\Log;

/**
 * 告警统一入口（I3.1）：所有业务告警都必须经此创建，
 * 统一应用去抖、静默、webhook 投递与日志，避免绕过通知链路。
 */
class AlertService
{
    /**
     * 创建告警（被静默/去抖时返回 null）。
     *
     * @param  int|null  $debounceMinutes  覆盖默认窗口（0=不做去抖）
     */
    public function raise(
        ?NpsServer $server,
        string $type,
        string $level,
        string $message,
        array $context = [],
        ?int $debounceMinutes = null,
    ): ?Alert {
        if ($this->isSilenced($server, $type)) {
            Log::info("alert silenced: {$type} on ".($server?->name ?? 'platform'));

            return null;
        }

        $debounce = $debounceMinutes ?? (int) config('nps.monitor.alert_debounce_minutes', 60);
        if ($debounce > 0) {
            $exists = Alert::where('server_id', $server?->id)
                ->where('type', $type)
                ->where('status', '!=', Alert::STATUS_RESOLVED)
                ->where('created_at', '>', now()->subMinutes($debounce))
                ->exists();
            if ($exists) {
                return null;
            }
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

        return $alert;
    }

    /**
     * 解决告警（幂等）。
     */
    public function resolve(Alert $alert): void
    {
        if ($alert->status === Alert::STATUS_RESOLVED) {
            return;
        }
        $alert->update([
            'status' => Alert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }

    /**
     * 静默匹配：server_id/type 为空的记录分别匹配全部服务器/全部类型。
     */
    public function isSilenced(?NpsServer $server, string $type): bool
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
