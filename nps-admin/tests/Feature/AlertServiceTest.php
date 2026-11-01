<?php

namespace Tests\Feature;

use App\Jobs\SendAlertWebhook;
use App\Models\AlertSilence;
use App\Models\NpsServer;
use App\Services\Monitor\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * I3.1：告警统一入口的去抖/静默/webhook。
 */
class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function server(): NpsServer
    {
        return NpsServer::create([
            'name' => 'alert-service-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
        ]);
    }

    public function test_raise_respects_silence_and_debounce(): void
    {
        $server = $this->server();
        $service = app(AlertService::class);

        AlertSilence::create([
            'server_id' => $server->id,
            'type' => 'unreachable',
            'reason' => 'maintenance',
            'until' => now()->addHour(),
        ]);
        $this->assertNull($service->raise($server, 'unreachable', 'critical', 'silenced'));
        $this->assertSame(0, \App\Models\Alert::count());

        AlertSilence::query()->delete();
        $this->assertNotNull($service->raise($server, 'unreachable', 'critical', 'first'));
        $this->assertNull($service->raise($server, 'unreachable', 'critical', 'debounced'));
        $this->assertSame(1, \App\Models\Alert::count());
    }

    public function test_raise_queues_webhook_when_configured(): void
    {
        Queue::fake();
        config(['nps.monitor.webhook_url' => 'https://hook.test/alert']);

        $alert = app(AlertService::class)->raise(null, 'cert_renew_failed', 'critical', 'job failed');
        $this->assertNotNull($alert);

        Queue::assertPushed(SendAlertWebhook::class, fn ($job) => $job->alertId === $alert->id);
    }
}
