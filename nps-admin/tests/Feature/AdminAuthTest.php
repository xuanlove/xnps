<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_receive_token(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', $admin->email)
            ->assertJsonPath('data.user.is_admin', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_non_admin_cannot_login_to_admin_ui(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'is_admin' => false,
        ]);

        $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertStatus(403);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'admin2@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
        ]);

        $this->postJson('/api/admin/login', [
            'email' => 'admin2@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_nps_admin_api_requires_authentication(): void
    {
        $this->getJson('/api/nps/servers')->assertStatus(401);
    }

    public function test_nps_admin_api_rejects_normal_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user, ['portal']);

        $this->getJson('/api/nps/servers')->assertStatus(403);
    }

    public function test_nps_admin_api_allows_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin']);

        $this->getJson('/api/nps/servers')->assertOk();
    }
}
