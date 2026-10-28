<?php

namespace Tests\Feature;

use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Services\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-1：流量批次用 CASE 一条 SQL 更新，验证映射与绑定正确。
 */
class FlowBatchUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_update_flows_maps_remote_ids(): void
    {
        $server = NpsServer::create([
            'name' => 'flow-batch',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'bridge_type' => 'tcp',
            'bridge_port' => 8024,
            'status' => 'active',
        ]);

        foreach ([101, 102] as $remoteId) {
            NpsClient::create([
                'server_id' => $server->id,
                'remote_id' => $remoteId,
                'vkey' => 'vkey-'.$remoteId,
                'remark' => '',
                'status' => true,
            ]);
        }

        $method = new \ReflectionMethod(SyncEngine::class, 'bulkUpdateFlows');
        $method->setAccessible(true);
        $affected = $method->invoke(app(SyncEngine::class), $server->id, [
            ['client_id' => 101, 'in' => 11, 'out' => 22],
            ['client_id' => 102, 'in' => 33, 'out' => 44],
        ]);

        $this->assertSame(2, $affected);
        $this->assertDatabaseHas('nps_clients', [
            'server_id' => $server->id,
            'remote_id' => 101,
            'flow_in' => 11,
            'flow_out' => 22,
        ]);
        $this->assertDatabaseHas('nps_clients', [
            'server_id' => $server->id,
            'remote_id' => 102,
            'flow_in' => 33,
            'flow_out' => 44,
        ]);
    }
}
