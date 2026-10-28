<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Services\Cert\CertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 证书自动续期（P1-2）：每日扫描到期证书，ACME 重新签发并按原部署滚动替换。
 * manual 来源只由监控告警，不自动签发。
 */
class RenewCertificatesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function handle(CertificateService $service): void
    {
        try {
            $stats = $service->renewExpiring();
            Log::info('certificate renewal finished', $stats);
        } catch (\Throwable $e) {
            Log::error('certificate renewal job failed: '.$e->getMessage());
            Alert::create([
                'server_id' => null,
                'type' => 'cert_renew_failed',
                'level' => 'critical',
                'message' => 'certificate renewal job failed: '.$e->getMessage(),
                'context' => ['error' => $e->getMessage()],
                'created_at' => now(),
            ]);
        }
    }
}
