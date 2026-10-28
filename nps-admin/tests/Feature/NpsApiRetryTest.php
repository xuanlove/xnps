<?php

namespace Tests\Feature;

use App\Models\NpsServer;
use App\Services\Nps\NpsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1-1：只有幂等的 GET / 只读 ext 端点允许重试；写操作超时不得重复提交
 */
class NpsApiRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_request_is_not_retried_on_connection_error(): void
    {
        config(['nps.http_retries' => 2]);
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/auth/gettime')) {
                return Http::response(['time' => time()]);
            }
            $attempts++;
            throw new ConnectionException('connection reset');
        });

        $client = new NpsApiClient($this->server());
        $response = $client->addClient(['vkey' => 'test-vkey']);

        $this->assertFalse($response->ok);
        $this->assertSame(1, $attempts);
    }

    public function test_read_ext_request_is_retried(): void
    {
        config(['nps.http_retries' => 1, 'nps.http_timeout' => 1]);
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/auth/gettime')) {
                return Http::response(['time' => time()]);
            }
            $attempts++;
            throw new ConnectionException('timeout');
        });

        $client = new NpsApiClient($this->server());
        $response = $client->extVersion();

        $this->assertFalse($response->ok);
        $this->assertSame(2, $attempts); // 1 次 + 1 次重试
    }

    private function server(): NpsServer
    {
        return NpsServer::create([
            'name' => 'retry-test',
            'api_url' => 'http://nps.test',
            'auth_key' => 'test-auth-key',
            'bridge_type' => 'tcp',
            'bridge_port' => 8024,
            'status' => 'active',
        ]);
    }
}
