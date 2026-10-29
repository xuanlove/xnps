<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * 健康检查（I3）：DB / 缓存 / 队列 / storage 四项，任一失败返回 503。
 * 公开端点，不返回异常详细信息（仅异常类名）。
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                $key = 'healthz:'.Str::random(8);
                Cache::put($key, 'ok', 5);
                if (Cache::get($key) !== 'ok') {
                    throw new \RuntimeException('cache read-back mismatch');
                }
                Cache::forget($key);
            }),
            'queue' => $this->check(fn () => Queue::size()),
            'storage' => $this->check(function () {
                if (! is_writable(storage_path())) {
                    throw new \RuntimeException('storage path not writable');
                }
            }),
        ];

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, duration_ms: int, error?: string}
     */
    private function check(\Closure $probe): array
    {
        $start = microtime(true);
        try {
            $probe();

            return ['ok' => true, 'duration_ms' => (int) round((microtime(true) - $start) * 1000)];
        } catch (\Throwable $e) {
            Log::error('healthz check failed: '.$e->getMessage());

            return [
                'ok' => false,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => class_basename($e),
            ];
        }
    }
}
