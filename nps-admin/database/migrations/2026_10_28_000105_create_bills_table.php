<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 账单（DESIGN.md §8.2）：月度结算 = 月费 + 超额流量 × 单价
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('period', 7)->comment('账期 YYYY-MM');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('plan_fee_cents')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0)->comment('本期总用量（字节）');
            $table->unsignedBigInteger('included_bytes')->default(0)->comment('套餐内含额度（字节）');
            $table->unsignedBigInteger('overage_bytes')->default(0)->comment('超额用量（字节）');
            $table->unsignedBigInteger('overage_fee_cents')->default(0);
            $table->unsignedBigInteger('total_fee_cents')->default(0);
            $table->string('status', 16)->default('pending')->comment('pending|paid|unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
