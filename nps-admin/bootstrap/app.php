<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // I3：健康检查/指标不经过 web 中间件（DB 故障时 session 会先失败）
            \Illuminate\Support\Facades\Route::get('/healthz', \App\Http\Controllers\HealthController::class);
            \Illuminate\Support\Facades\Route::get('/metrics', \App\Http\Controllers\MetricsController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 请求关联 ID：写入日志上下文 + 响应头（I3）
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);

        // admin 别名中间件：校验 users.is_admin（管理端路由）
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Policy 拒绝 → 403 JSON（API 语义）
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'forbidden: '.$e->getMessage()], 403);
            }
        });
    })->create();
