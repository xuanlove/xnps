<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\NpsServer;
use App\Models\User;
use App\Services\Cert\HttpsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M1：启用 HTTPS 默认校验证书链；仅显式 tls_verify=false 时才跳过并留告警。
 */
class HttpsEnableDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin']);

        $server = NpsServer::create([
            'name' => 'https-default',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
        ]);
        $certificate = Certificate::create([
            'name' => 'cert-default',
            'domains' => ['nps.example.com'],
            'cert_pem' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'key_pem' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'sha256' => str_repeat('a', 64),
            'not_after' => now()->addDays(90),
            'source' => 'manual',
            'active' => true,
        ]);

        return [$server, $certificate];
    }

    public function test_enable_https_verifies_certificate_by_default(): void
    {
        [$server, $certificate] = $this->fixture();
        $captured = null;

        $this->mock(HttpsProvisioner::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('enable')->once()
                ->andReturnUsing(function ($server, $certificate, bool $tlsVerify) use (&$captured) {
                    $captured = $tlsVerify;

                    return ['steps' => [], 'api_url' => 'https://nps.test'];
                });
        });

        $this->postJson("/api/admin/certificates/{$certificate->id}/enable-https/{$server->id}")
            ->assertOk();

        $this->assertTrue($captured, 'tls_verify must default to true');
    }

    public function test_explicit_self_signed_opt_out_is_logged(): void
    {
        [$server, $certificate] = $this->fixture();
        $captured = null;
        Log::spy();

        $this->mock(HttpsProvisioner::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('enable')->once()
                ->andReturnUsing(function ($server, $certificate, bool $tlsVerify) use (&$captured) {
                    $captured = $tlsVerify;

                    return ['steps' => [], 'api_url' => 'https://nps.test'];
                });
        });

        $this->postJson("/api/admin/certificates/{$certificate->id}/enable-https/{$server->id}", [
            'tls_verify' => false,
        ])->assertOk();

        $this->assertFalse($captured);
        Log::shouldHaveReceived('warning')->once();
    }
}
