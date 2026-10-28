<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 告警记录（阶段 3：阈值/到期/不可达；通知渠道为日志 + 可选 webhook）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->comment('空=平台级告警');
            $table->string('type', 32)->comment('cpu_high|mem_high|unreachable|cert_expiring|sync_lag|tunnel_down');
            $table->string('level', 8)->default('warning')->comment('info|warning|critical');
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('notified')->default(false)->comment('webhook 是否已通知');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
