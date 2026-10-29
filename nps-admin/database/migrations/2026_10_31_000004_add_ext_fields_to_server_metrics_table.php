<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// I3：/ext/system 新增磁盘占用、fd、goroutine 峰值采样落库
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->float('disk_used_percent')->nullable()->after('load1');
            $table->unsignedInteger('open_fds')->nullable()->after('tcp_established');
            $table->unsignedInteger('goroutines_peak')->nullable()->after('goroutines');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn(['disk_used_percent', 'open_fds', 'goroutines_peak']);
        });
    }
};
