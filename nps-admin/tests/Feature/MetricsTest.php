<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Models\UpgradeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I3：/metrics Prometheus 输出与 token 保护。
 */
class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): NpsServer
    {
        $server = NpsServer::create([
            'name' => 'metrics-test',
            'api_url' => 'https://nps.test',
            'auth_key' => 'test-auth-key',
            'is_reachable' => true,
            'last_synced_at' => now()->subSeconds(30),
        ]);
        NpsClient::create([
            'server_id' => $server->id,
            'remote_id' => 1,
            'vkey' => 'vkey-1',
            'is_connect' => true,
        ]);
        Alert::create([
            'server_id' => $server->id,
            'type' => 'cpu_high',
            'level' => 'warning',
            'status' => Alert::STATUS_OPEN,
            'message' => 'cpu high',
            'context' => [],
            'created_at' => now(),
        ]);
        UpgradeRun::create([
            'artifact_id' => 1,
            'server_ids' => [$server->id],
            'status' => 'failed',
            'started_at' => now(),
        ]);

        return $server;
    }

    public function test_metrics_exposes_core_series(): void
    {
        $this->fixture();

        $response = $this->get('/metrics');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $body = $response->getContent();

        $this->assertStringContainsString('nps_server_up{server="metrics-test"} 1', $body);
        $this->assertStringContainsString('nps_sync_lag_seconds{server="metrics-test"}', $body);
        $this->assertStringContainsString('nps_server_clients_online{server="metrics-test"} 1', $body);
        $this->assertStringContainsString('nps_queue_jobs{queue="nps-sync"}', $body);
        $this->assertStringContainsString('nps_api_requests_window{window="15m"}', $body);
        $this->assertStringContainsString('nps_billing_settle_duration_seconds{stat="avg"}', $body);
        $this->assertStringContainsString('nps_upgrade_runs{status="failed"} 1', $body);
        $this->assertStringContainsString('nps_alerts_open{level="warning"} 1', $body);
    }

    public function test_metrics_requires_token_when_configured(): void
    {
        config(['nps.metrics_token' => 'scrape-secret']);

        $this->get('/metrics')->assertStatus(403);
        $this->withHeader('Authorization', 'Bearer scrape-secret')->get('/metrics')->assertOk();
    }
}
