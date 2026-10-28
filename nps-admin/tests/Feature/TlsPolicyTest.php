<?php

namespace Tests\Feature;

use App\Models\NpsServer;
use App\Services\Nps\NpsApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M1：require_https=true 时拒绝明文 HTTP 下的敏感写操作。
 */
class TlsPolicyTest extends TestCase
{
    public function test_sensitive_operation_over_plain_http_is_rejected_when_required(): void
    {
        config(['nps.require_https' => true]);
        Http::fake();

        $server = new NpsServer([
            'name' => 'tls-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
        ]);

        $response = (new NpsApiClient($server))->call('POST', '/ext/cert', [], []);

        $this->assertFalse($response->ok);
        $this->assertStringContainsString('plain HTTP', $response->msg);
        Http::assertNothingSent();
    }

    public function test_read_operation_is_not_blocked_by_tls_policy(): void
    {
        config(['nps.require_https' => true]);
        Http::fake(['*' => Http::response(['status' => 1, 'data' => ['time' => time()]])]);

        $server = new NpsServer([
            'name' => 'tls-read',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'is_reachable' => true,
        ]);

        $response = (new NpsApiClient($server))->extVersion();

        $this->assertTrue($response->ok);
    }
}
