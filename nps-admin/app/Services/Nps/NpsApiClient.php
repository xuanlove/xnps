<?php

namespace App\Services\Nps;

use App\Models\NpsApiLog;
use App\Models\NpsServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * nps WebAPI 签名 SDK（DESIGN.md §12.1）
 *
 * 每台服务器一个实例（NpsApiClientFactory）。职责：
 *  - 签名注入：auth_key = md5(nps.conf auth_key + timestamp)，±20s 窗口
 *  - 时钟校准：/auth/gettime（免鉴权）按 baseUrl 缓存偏移
 *  - 三种响应格式统一（NpsResponse）
 *  - 超时 + 指数退避重试（连接错误 / 5xx）
 *  - 审计：写操作必记 nps_api_logs，读操作按配置
 */
class NpsApiClient
{
    /** @var array<string, int> 按 baseUrl 缓存的时钟偏移（秒） */
    private static array $clockOffsets = [];

    public function __construct(
        public readonly NpsServer $server,
    ) {}

    // ------------------------------------------------------------------
    // 原生端点（DESIGN.md §2.2）
    // ------------------------------------------------------------------

    public function clientList(int $offset = 0, int $limit = 100, string $search = ''): NpsResponse
    {
        return $this->call('POST', '/client/list', [
            'offset' => $offset, 'limit' => $limit, 'search' => $search,
        ]);
    }

    public function addClient(array $params): NpsResponse
    {
        return $this->call('POST', '/client/add', $params);
    }

    public function editClient(int $id, array $params): NpsResponse
    {
        $params['id'] = $id;

        return $this->call('POST', '/client/edit', $params);
    }

    public function delClient(int $id): NpsResponse
    {
        return $this->call('POST', '/client/del', ['id' => $id]);
    }

    public function changeClientStatus(int $id, bool $status): NpsResponse
    {
        return $this->call('POST', '/client/changestatus', [
            'id' => $id, 'status' => $status ? 1 : 0,
        ]);
    }

    public function addTunnel(array $params): NpsResponse
    {
        return $this->call('POST', '/index/add', $params);
    }

    public function editTunnel(int $id, array $params): NpsResponse
    {
        $params['id'] = $id;

        return $this->call('POST', '/index/edit', $params);
    }

    public function delTunnel(int $id): NpsResponse
    {
        return $this->call('POST', '/index/del', ['id' => $id]);
    }

    public function startTunnel(int $id): NpsResponse
    {
        return $this->call('POST', '/index/start', ['id' => $id]);
    }

    public function stopTunnel(int $id): NpsResponse
    {
        return $this->call('POST', '/index/stop', ['id' => $id]);
    }

    public function addHost(array $params): NpsResponse
    {
        return $this->call('POST', '/index/addhost', $params);
    }

    public function editHost(int $id, array $params): NpsResponse
    {
        $params['id'] = $id;

        return $this->call('POST', '/index/edithost', $params);
    }

    public function delHost(int $id): NpsResponse
    {
        return $this->call('POST', '/index/delhost', ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // nps-admin-ext 扩展端点（DESIGN.md §5.2）
    // ------------------------------------------------------------------

    public function extVersion(): NpsResponse
    {
        return $this->call('POST', '/ext/version');
    }

    public function extChanges(int $since): NpsResponse
    {
        return $this->call('POST', '/ext/changes', ['since' => $since]);
    }

    public function extOnline(): NpsResponse
    {
        return $this->call('POST', '/ext/online');
    }

    public function extFlow(int $shard, int $total): NpsResponse
    {
        return $this->call('POST', '/ext/flow', ['shard' => $shard, 'total' => $total]);
    }

    public function extList(string $type, int $cursor = 0, int $limit = 500): NpsResponse
    {
        return $this->call('POST', '/ext/list', [
            'type' => $type, 'cursor' => $cursor, 'limit' => $limit,
        ]);
    }

    public function extSystem(): NpsResponse
    {
        return $this->call('POST', '/ext/system');
    }

    public function extConfigGet(): NpsResponse
    {
        return $this->call('GET', '/ext/config');
    }

    public function extConfigSet(array $entries, bool $restart = false): NpsResponse
    {
        return $this->call('POST', '/ext/config', [], [
            'entries' => $entries, 'restart' => $restart,
        ]);
    }

    public function extRestart(): NpsResponse
    {
        return $this->call('POST', '/ext/restart');
    }

    // ------------------------------------------------------------------
    // 核心调用
    // ------------------------------------------------------------------

    /**
     * @param  array  $params  query/form 参数（签名自动注入）
     * @param  array|null  $json  JSON 请求体（ext 端点用；签名仍走 query）
     */
    public function call(string $method, string $path, array $params = [], ?array $json = null): NpsResponse
    {
        $method = strtoupper($method);
        $start = microtime(true);
        $params = $this->signParams($params);

        $attempt = function () use ($method, $path, $params, $json) {
            // TLS 校验按服务器配置（自签证书场景 false，CA 签发 true）
            $req = Http::timeout(config('nps.http_timeout'))
                ->withOptions(['verify' => (bool) ($this->server->tls_verify ?? true)]);

            if ($json !== null) {
                $req = $req->asJson();
            }

            $url = rtrim($this->server->api_url, '/').$path;
            $response = match ($method) {
                'GET' => $req->get($url, $params),
                default => $json !== null
                    ? $req->send($method, $url, ['query' => $params, 'json' => $json])
                    : $req->send($method, $url, ['query' => $params, 'form_params' => $params]),
            };

            // 302：未通过签名鉴权，被重定向到登录页
            if ($response->status() === 302) {
                return new NpsResponse(
                    ok: false,
                    msg: 'auth failed: signature rejected (302 redirect to login)',
                    statusCode: 302,
                );
            }

            $body = $response->json();
            if (! is_array($body)) {
                return new NpsResponse(
                    ok: false,
                    msg: 'non-json response: HTTP '.$response->status(),
                    statusCode: $response->status(),
                );
            }

            return NpsResponse::fromArray($body, $response->status());
        };

        try {
            $result = retry(
                max(1, (int) config('nps.http_retries')) + 1,
                $attempt,
                function (int $attempts) {
                    // 指数退避：1s, 2s...
                    return $attempts * 1000;
                },
                fn (\Throwable $e) => true, // 连接错误 / 5xx / 超时均重试
            );
        } catch (\Throwable $e) {
            $result = new NpsResponse(ok: false, msg: 'request failed: '.$e->getMessage());
        }

        $this->audit($method, $path, $params, $json, $result, microtime(true) - $start);
        $this->server->forceFill(['is_reachable' => $result->ok || $result->statusCode !== null])->saveQuietly();

        return $result;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 签名注入（源码核实：md5(auth_key + timestamp)，±20s 窗口）
     */
    private function signParams(array $params): array
    {
        $timestamp = time() + $this->clockOffset();
        $params['timestamp'] = $timestamp;
        $params['auth_key'] = md5($this->server->auth_key.$timestamp);

        return $params;
    }

    /**
     * 时钟偏移：首次（或偏移缓存失效）时经 /auth/gettime 校准。
     * 该端点免鉴权（AuthController 不继承 BaseController）。
     */
    private function clockOffset(): int
    {
        $key = $this->server->api_url;
        if (array_key_exists($key, self::$clockOffsets)) {
            return self::$clockOffsets[$key];
        }

        $offset = 0;
        try {
            $resp = Http::timeout(config('nps.http_timeout'))
                ->withOptions(['verify' => (bool) ($this->server->tls_verify ?? true)])
                ->post(rtrim($this->server->api_url, '/').'/auth/gettime');
            $body = $resp->json();
            if (isset($body['time']) && is_numeric($body['time'])) {
                $offset = max(-60, min(60, (int) $body['time'] - time()));
            }
        } catch (\Throwable $e) {
            Log::warning('nps clock calibration failed, using local time', [
                'server' => $this->server->name, 'error' => $e->getMessage(),
            ]);
        }

        self::$clockOffsets[$key] = $offset;

        return $offset;
    }

    /**
     * 审计：写操作（非 GET）必记；读操作按配置
     */
    private function audit(string $method, string $path, array $params, ?array $json, NpsResponse $result, float $elapsed): void
    {
        $isWrite = $method !== 'GET';
        if (! $isWrite && ! config('nps.audit_reads')) {
            return;
        }

        try {
            NpsApiLog::create([
                'server_id' => $this->server->id,
                'method' => $method,
                'endpoint' => $path,
                'request' => [
                    'params' => $this->redact($params),
                    'json' => $json,
                ],
                'response' => $result->raw ?: null,
                'status_code' => $result->statusCode,
                'duration_ms' => (int) round($elapsed * 1000),
                'ok' => $result->ok,
                'error' => $result->ok ? null : ($result->msg ?: 'unknown'),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不阻断业务
            Log::error('nps audit log write failed: '.$e->getMessage());
        }
    }

    /**
     * 脱敏：签名参数不落审计日志
     */
    private function redact(array $params): array
    {
        unset($params['auth_key'], $params['timestamp']);

        return $params;
    }
}
