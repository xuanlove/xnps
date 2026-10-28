<?php

namespace Tests\Feature;

use App\Models\NpsClient;
use App\Models\NpsHost;
use App\Models\NpsServer;
use App\Models\NpsTunnel;
use App\Services\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2-6：远端对账为空时清理本地镜像（带配置开关）。
 */
class ReconcileEmptyTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_remote_purges_local_mirror(): void
    {
        $server = NpsServer::create([
            'name' => 'reconcile-empty',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'bridge_type' => 'tcp',
            'bridge_port' => 8024,
            'status' => 'active',
        ]);
        $client = NpsClient::create([
            'server_id' => $server->id, 'remote_id' => 1, 'vkey' => 'v1', 'status' => true,
        ]);
        $tunnel = NpsTunnel::create([
            'server_id' => $server->id, 'remote_id' => 1, 'client_id' => $client->id,
            'client_remote_id' => 1, 'port' => 10001, 'mode' => 'tcp', 'status' => true,
        ]);
        $host = NpsHost::create([
            'server_id' => $server->id, 'remote_id' => 1, 'client_id' => $client->id,
            'client_remote_id' => 1, 'host' => 'a.example.com', 'target' => '127.0.0.1:80',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/gettime')) {
                return Http::response(['time' => time()]);
            }
            if (str_contains($request->url(), '/ext/version')) {
                return Http::response(['status' => 1, 'data' => [
                    'version' => '0.26.10', 'core_version' => '0.26.0',
                    'ext_version' => '0.3.0', 'change_seq' => 0,
                ]]);
            }

            return Http::response(['status' => 1, 'data' => [
                'items' => [], 'next_cursor' => 0, 'total' => 0,
            ]]);
        });

        app(SyncEngine::class)->reconcile($server);

        $this->assertDatabaseMissing('nps_clients', ['id' => $client->id]);
        $this->assertDatabaseMissing('nps_tunnels', ['id' => $tunnel->id]);
        $this->assertDatabaseMissing('nps_hosts', ['id' => $host->id]);
    }
}
