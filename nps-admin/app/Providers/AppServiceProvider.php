<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 慢查询日志（I3）：阈值毫秒，0=关闭。不记录绑定参数（避免敏感值落盘）。
        $threshold = (int) config('nps.slow_query_ms', 0);
        if ($threshold > 0) {
            DB::listen(function ($query) use ($threshold) {
                if ($query->time < $threshold) {
                    return;
                }
                Log::warning('slow query', [
                    'connection' => $query->connectionName,
                    'time_ms' => $query->time,
                    'sql' => $query->sql,
                ]);
            });
        }
    }
}
