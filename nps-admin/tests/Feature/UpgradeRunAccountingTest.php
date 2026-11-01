<?php

namespace Tests\Feature;

use App\Models\NpsServer;
use App\Models\UpgradeArtifact;
use App\Models\UpgradeRun;
use App\Services\Upgrade\UpgradeOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I3.1：升级 Run 统计（skipped 不算失败，全 skipped 记为 aborted）。
 */
class UpgradeRunAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_skipped_servers_abort_run_without_counting_failure(): void
    {
        $artifact = UpgradeArtifact::create([
            'version' => '0.26.10-ext0.3.0',
            'channel' => 'stable',
            'sha256' => str_repeat('a', 64),
            'filename' => 'xnps-test',
            'size_bytes' => 1,
            'compatible_core_version' => '0.26.0',
            'download_token' => 'test-token',
        ]);
        $server = NpsServer::create([
            'name' => 'disabled-server',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'status' => 'disabled',
        ]);

        $run = UpgradeRun::create([
            'artifact_id' => $artifact->id,
            'server_ids' => [$server->id],
            'status' => UpgradeRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $result = app(UpgradeOrchestrator::class)->execute($run, $artifact, [$server->id], $server->id, 0);

        $this->assertSame(UpgradeRun::STATUS_ABORTED, $result->status);
        $this->assertSame(1, $result->summary['skipped']);
        $this->assertSame(0, $result->summary['attempted']);
        $this->assertSame(0, $result->summary['failed']);
    }
}
