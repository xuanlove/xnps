<?php

namespace Tests\Feature;

use App\Models\UpgradeArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L1：制品下载 token 过期、错误 token、流式下载与轮换。
 */
class ArtifactDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_requires_valid_unexpired_token(): void
    {
        Storage::fake('local');
        $artifact = $this->artifact(now()->addDay());
        Storage::put($artifact->filename, 'binary-content');

        $this->get("/api/artifacts/{$artifact->id}/download/wrongtoken")->assertStatus(403);

        $this->get("/api/artifacts/{$artifact->id}/download/{$artifact->download_token}")
            ->assertOk()
            ->assertHeader('X-Artifact-Sha256', $artifact->sha256)
            ->assertStreamedContent('binary-content');

        $expired = $this->artifact(now()->subMinute());
        Storage::put($expired->filename, 'binary-content');
        $this->get("/api/artifacts/{$expired->id}/download/{$expired->download_token}")
            ->assertStatus(410);
    }

    public function test_admin_can_rotate_download_token(): void
    {
        Storage::fake('local');
        $artifact = $this->artifact(now()->addDay());
        $oldToken = $artifact->download_token;

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin']);

        $response = $this->postJson("/api/admin/upgrades/artifacts/{$artifact->id}/rotate-token")
            ->assertOk()
            ->assertJsonPath('data.id', $artifact->id);

        $newToken = $response->json('data.token');
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame($newToken, $artifact->fresh()->download_token);
        $this->assertNotNull($artifact->fresh()->download_token_expires_at);
    }

    private function artifact(\DateTimeInterface $expiresAt): UpgradeArtifact
    {
        return UpgradeArtifact::create([
            'version' => 'test-'.uniqid(),
            'channel' => 'stable',
            'sha256' => hash('sha256', 'binary-content'),
            'filename' => 'artifacts/1/nps',
            'size_bytes' => strlen('binary-content'),
            'compatible_core_version' => '0.26.0',
            'notes' => '',
            'download_token' => 'token'.uniqid(),
            'download_token_expires_at' => $expiresAt,
        ]);
    }
}
