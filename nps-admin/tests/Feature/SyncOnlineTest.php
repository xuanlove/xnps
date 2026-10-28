<?php

namespace Tests\Feature;

use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Services\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2-2：在线状态只 diff 本地在线集合与远端在线集合。
 */
class SyncOnlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_diff_updates_only_changed_rows(): void
    {
        $server = $this->server();
        $online = $this->client($server, 1, true);
        $offline = $this->client($server, 2, false);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/gettime')) {
                return Http::response(['time' => time()]);
            }

            return Http::response(['status' => 1, 'data' => ['online' => [2]]]);
        });

        $result = app(SyncEngine::class)->syncOnline($server);

        $this->assertStringContainsString('online=1', $result);
        $this->assertFalse($online->fresh()->is_connect);
        $this->assertTrue($offline->fresh()->is_connect);
    }

    private function server(): NpsServer
    {
        return NpsServer::create([
            'name' => 'online-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'bridge_type' => 'tcp',
            'bridge_port' => 8024,
            'status' => 'active',
        ]);
    }

    private function client(NpsServer $server, int $remoteId, bool $isConnect): NpsClient
    {
        return NpsClient::create([
            'server_id' => $server->id,
            'remote_id' => $remoteId,
            'vkey' => 'vkey-'.$remoteId,
            'remark' => '',
            'status' => true,
            'is_connect' => $isConnect,
        ]);
    }
}
