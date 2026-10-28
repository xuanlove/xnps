<?php

namespace Tests\Feature;

use App\Models\NpsServer;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-3：端口池一次查询已占用端口，自动分配不重复且排除保留端口。
 */
class PortPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_allocate_skips_reserved_and_allocated_ports(): void
    {
        $server = $this->server();
        $service = app(ProvisioningService::class);
        $range = [[20000, 20005]];

        $first = $service->holdPort($server, null, $range, [20000]);
        $this->assertNotSame(20000, $first);

        $second = $service->holdPort($server, null, $range, [20000]);
        $this->assertNotSame($first, $second);

        $this->assertDatabaseHas('port_allocations', ['server_id' => $server->id, 'port' => $first]);
        $this->assertDatabaseHas('port_allocations', ['server_id' => $server->id, 'port' => $second]);
    }

    public function test_preferred_port_conflict_throws(): void
    {
        $server = $this->server();
        $service = app(ProvisioningService::class);

        $service->holdPort($server, 21000);

        $this->expectException(ProvisioningException::class);
        $service->holdPort($server, 21000);
    }

    private function server(): NpsServer
    {
        return NpsServer::create([
            'name' => 'port-pool-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'bridge_type' => 'tcp',
            'bridge_port' => 8024,
            'status' => 'active',
        ]);
    }
}
