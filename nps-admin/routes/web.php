<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// /healthz 与 /metrics 在 bootstrap/app.php 的 withRouting(then:) 注册，
// 不经过 web 中间件（DB 故障时 session 中间件会抢先失败）。

// 管理控制台（Vue SPA，前端路由由 /admin/{any?} 兜底）
Route::get('/admin/{any?}', function () {
    return view('admin');
})->where('any', '.*')->name('admin.ui');
