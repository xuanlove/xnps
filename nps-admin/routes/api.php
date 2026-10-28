<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\HostController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\TunnelController;
use Illuminate\Support\Facades\Route;

// ---- P2 用户自助门户（DESIGN.md §8）----
Route::prefix('portal')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::get('/clients', [PortalController::class, 'clients']);
        Route::get('/clients/{client}', [PortalController::class, 'clientShow']);
        Route::post('/clients', [PortalController::class, 'requestClient']);
        Route::post('/clients/{client}/tunnels', [PortalController::class, 'requestTunnel']);

        Route::get('/approvals', [PortalController::class, 'approvals']);
        Route::get('/bills', [PortalController::class, 'bills']);
    });
});

// ---- 管理端（is_admin）：审批 / 套餐 / 充值 / 结算 ----
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        Route::get('/approvals', [AdminController::class, 'approvals']);
        Route::post('/approvals/{approval}/approve', [AdminController::class, 'approve']);
        Route::post('/approvals/{approval}/reject', [AdminController::class, 'reject']);

        Route::get('/plans', [AdminController::class, 'plans']);
        Route::post('/plans', [AdminController::class, 'planStore']);

        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{user}/recharge', [AdminController::class, 'recharge']);
        Route::post('/settle', [AdminController::class, 'settle']);
    });

// ---- nps 集中管理 API（阶段 1：管理面，生产应叠加 admin 中间件）----

Route::prefix('nps')->group(function () {
    // 服务器纳管
    Route::get('/servers', [ServerController::class, 'index']);
    Route::post('/servers', [ServerController::class, 'store']);
    Route::get('/servers/{server}', [ServerController::class, 'show']);
    Route::post('/servers/{server}/sync', [ServerController::class, 'sync']);
    Route::delete('/servers/{server}', [ServerController::class, 'destroy']);

    // client 管理
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

    // 隧道管理
    Route::get('/tunnels', [TunnelController::class, 'index']);
    Route::post('/tunnels', [TunnelController::class, 'store']);
    Route::post('/tunnels/{tunnel}/start', [TunnelController::class, 'start']);
    Route::post('/tunnels/{tunnel}/stop', [TunnelController::class, 'stop']);
    Route::delete('/tunnels/{tunnel}', [TunnelController::class, 'destroy']);

    // 域名解析管理
    Route::get('/hosts', [HostController::class, 'index']);
    Route::post('/hosts', [HostController::class, 'store']);
    Route::delete('/hosts/{host}', [HostController::class, 'destroy']);
});
