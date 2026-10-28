<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Services\Cert\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-2：manual 证书不参与自动续签（只由监控告警）。
 */
class CertificateRenewTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_certificate_is_skipped(): void
    {
        Certificate::create([
            'name' => 'manual-cert',
            'domains' => ['manual.example.com'],
            'cert_pem' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'key_pem' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'sha256' => str_repeat('a', 64),
            'not_after' => now()->addDays(5),
            'source' => 'manual',
            'active' => true,
        ]);

        $stats = app(CertificateService::class)->renewExpiring();

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['renewed']);
    }
}
