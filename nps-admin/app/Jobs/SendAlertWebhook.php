<?php

namespace App\Jobs;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 告警 webhook 投递（I3）：指数退避重试，失败计数与最后一次错误落库。
 *
 * 与 MonitoringService::raise 解耦的原因：webhook 超时/失败不应阻塞
 * 5 分钟一次的告警巡检；队列重试保证外部通知系统抖动时不丢告警。
 */
class SendAlertWebhook implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    /** 重试间隔（秒）：10s / 30s / 2min / 5min / 10min */
    public array $backoff = [10, 30, 120, 300, 600];

    public function __construct(
        public readonly int $alertId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->alertId;
    }

    public function handle(): void
    {
        $alert = Alert::find($this->alertId);
        if (! $alert || $alert->status === Alert::STATUS_RESOLVED) {
            return;
        }
        $url = (string) config('nps.monitor.webhook_url');
        if ($url === '') {
            return;
        }

        $alert->increment('notify_attempts');
        $alert->refresh();

        try {
            $resp = Http::timeout(10)->post($url, [
                'alert_id' => $alert->id,
                'level' => $alert->level,
                'type' => $alert->type,
                'status' => $alert->status,
                'server' => $alert->server?->name,
                'message' => $alert->message,
                'context' => $alert->context,
                'at' => now()->toIso8601String(),
            ]);
            if (! $resp->successful()) {
                throw new \RuntimeException('webhook http '.$resp->status());
            }
            $alert->update([
                'notified' => true,
                'notified_at' => now(),
                'notify_error' => null,
            ]);
        } catch (\Throwable $e) {
            $alert->update([
                'notified' => false,
                'notify_error' => mb_substr($e->getMessage(), 0, 250),
            ]);
            Log::warning("alert webhook failed (attempt {$alert->notify_attempts}): {$e->getMessage()}", [
                'alert_id' => $alert->id,
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Alert::where('id', $this->alertId)->update([
            'notified' => false,
            'notify_error' => mb_substr('final: '.$e->getMessage(), 0, 250),
        ]);
    }
}
