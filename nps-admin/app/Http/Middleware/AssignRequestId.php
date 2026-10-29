<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 请求关联 ID（I3）：写入日志上下文并回显 X-Request-Id。
 * 上游（Nginx/网关）已带合法 ID 时沿用，便于跨层排查。
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->headers->get('X-Request-Id', '');
        if (! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id)) {
            $id = (string) Str::ulid();
        }

        Log::withContext([
            'request_id' => $id,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
