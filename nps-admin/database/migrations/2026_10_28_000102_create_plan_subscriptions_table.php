<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 套餐订阅：用户当前生效套餐与周期
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable()->comment('空=长期有效');
            $table->string('status', 16)->default('active')->comment('active|expired|cancelled');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_subscriptions');
    }
};
