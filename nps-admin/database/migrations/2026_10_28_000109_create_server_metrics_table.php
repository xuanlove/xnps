<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 服务器监控采样（DESIGN.md §5.2 /ext/system 轮询落库，历史曲线由管理端自采）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->float('cpu_percent')->nullable();
            $table->float('mem_used_percent')->nullable();
            $table->float('load1')->nullable();
            $table->unsignedInteger('tcp_established')->nullable();
            $table->unsignedInteger('client_online')->default(0);
            $table->unsignedInteger('goroutines')->nullable();
            $table->timestamp('collected_at')->useCurrent();

            $table->index(['server_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
