<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 套餐（DESIGN.md §8.2）：月费 + 包含隧道数 + 月流量额度 + 带宽上限
// 金额单位统一为「分」（integer）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->unsignedBigInteger('monthly_fee_cents')->default(0)->comment('月费（分）');
            $table->unsignedInteger('max_tunnels')->default(0)->comment('包含隧道数，0 不限');
            $table->unsignedBigInteger('monthly_flow_mb')->default(0)->comment('月流量额度（MB），0 不限');
            $table->unsignedInteger('rate_limit_kb')->default(0)->comment('带宽上限（KB/S），0 不限');
            $table->unsignedInteger('max_conn')->default(0)->comment('并发连接上限，0 不限');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
