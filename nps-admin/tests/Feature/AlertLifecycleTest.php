<?php

namespace Tests\Feature;

use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertSilence;
use App\Models\Certificate;
use App\Models\NpsServer;
use App\Models\User;
use App\Services\Monitor\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * I3：告警去抖 / 静默 / 自动解决 / 确认与静默 API / webhook 重试。
 */
class AlertLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function server(bool $reachable = false): NpsServer
    {
        return NpsServer::create([
            'name' => 'alert-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'is_reachable' => $reachable,
        ]);
    }

    public function test_unreachable_alert_debounces_and_auto_resolves(): void
    {
        config(['nps.monitor.webhook_url' => '', 'nps.monitor.alert_debounce_minutes' => 60]);
        $server = $this->server(false);
        $service = app(MonitoringService::class);

        $this->assertSame(1, $service->checkServer($server));
        $alert = Alert::firstOrFail();
        $this->assertSame(Alert::STATUS_OPEN, $alert->status);

        // 去抖窗口内不重复
        $this->assertSame(0, $service->checkServer($server));

        // 条件恢复 → 自动 resolve
        $server->update(['is_reachable' => true]);
        $service->checkServer($server);
        $this->assertSame(Alert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_silence_suppresses_new_alerts(): void
    {
        config(['nps.monitor.webhook_url' => '']);
        $server = $this->server(false);
        AlertSilence::create([
            'server_id' => $server->id,
            'type' => 'unreachable',
            'reason' => 'maintenance',
            'until' => now()->addHour(),
        ]);

        $this->assertSame(0, app(MonitoringService::class)->checkServer($server));
        $this->assertSame(0, Alert::count());
    }

    public function test_certificate_alert_auto_resolves_outside_renewal_window(): void
    {
        config(['nps.monitor.webhook_url' => '']);
        $cert = Certificate::create([
            'name' => 'renewed-cert',
            'domains' => ['renewed.example.com'],
            'cert_pem' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'key_pem' => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'sha256' => str_repeat('b', 64),
            'not_after' => now()->addDays(5),
            'source' => 'manual',
            'active' => true,
        ]);
        $service = app(MonitoringService::class);

        $this->assertSame(1, $service->checkCertificates());
        $alert = Alert::where('type', 'cert_expiring')->firstOrFail();

        $cert->update(['not_after' => now()->addDays(90)]);
        $service->checkCertificates();

        $this->assertSame(Alert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_admin_can_acknowledge_and_resolve_alert(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin']);
        $alert = Alert::create([
            'server_id' => null,
            'type' => 'sync_lag',
            'level' => 'warning',
            'status' => Alert::STATUS_OPEN,
            'message' => 'lag',
            'context' => [],
            'created_at' => now(),
        ]);

        $this->postJson("/api/admin/monitor/alerts/{$alert->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_ACKNOWLEDGED)
            ->assertJsonPath('data.acknowledged_by', $admin->id);

        $this->postJson("/api/admin/monitor/alerts/{$alert->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', Alert::STATUS_RESOLVED);
    }

    public function test_admin_can_create_list_and_remove_silence(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin']);
        $server = $this->server(true);

        $created = $this->postJson('/api/admin/monitor/alerts/silence', [
            'server_id' => $server->id,
            'type' => 'cpu_high',
            'minutes' => 30,
            'reason' => 'upgrade window',
        ])->assertCreated()->json('data');

        $this->getJson('/api/admin/monitor/silences')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/admin/monitor/silences/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_webhook_job_retries_and_records_error(): void
    {
        config(['nps.monitor.webhook_url' => 'https://hook.test/alert']);
        $alert = Alert::create([
            'server_id' => null,
            'type' => 'cert_expiring',
            'level' => 'warning',
            'status' => Alert::STATUS_OPEN,
            'message' => 'cert expiring',
            'context' => [],
            'created_at' => now(),
        ]);

        Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(200)]);
        $job = new SendAlertWebhook($alert->id);

        try {
            $job->handle();
            $this->fail('first webhook attempt should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('webhook http 500', $e->getMessage());
        }
        $this->assertSame(1, $alert->fresh()->notify_attempts);
        $this->assertNotNull($alert->fresh()->notify_error);

        $job->handle();
        $this->assertTrue($alert->fresh()->notified);
        $this->assertSame(2, $alert->fresh()->notify_attempts);
        $this->assertNotNull($alert->fresh()->notified_at);
    }
}
