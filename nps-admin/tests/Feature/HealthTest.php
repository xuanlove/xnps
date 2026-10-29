<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * I3：/healthz + 请求关联 ID。
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_reports_ok_and_request_id(): void
    {
        $response = $this->getJson('/healthz');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.queue.ok', true);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_healthz_returns_503_when_a_check_fails(): void
    {
        Queue::shouldReceive('size')->once()->andThrow(new \RuntimeException('queue down'));

        $this->getJson('/healthz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.error', 'RuntimeException');
    }

    public function test_valid_upstream_request_id_is_reused(): void
    {
        $this->getJson('/healthz', ['X-Request-Id' => 'gw-abc12345'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'gw-abc12345');
    }

    public function test_invalid_request_id_is_replaced(): void
    {
        $response = $this->getJson('/healthz', ['X-Request-Id' => 'bad id']);

        $response->assertOk();
        $this->assertNotSame('bad id', $response->headers->get('X-Request-Id'));
    }
}
