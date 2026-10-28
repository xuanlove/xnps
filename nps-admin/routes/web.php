<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 管理控制台（Vue SPA，前端路由由 /admin/{any?} 兜底）
Route::get('/admin/{any?}', function () {
    return view('admin');
})->where('any', '.*')->name('admin.ui');
