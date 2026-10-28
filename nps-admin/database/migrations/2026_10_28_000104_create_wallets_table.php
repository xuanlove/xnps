<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 钱包与流水（DESIGN.md §8.2）：金额单位「分」
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->bigInteger('balance_cents')->default(0)->comment('余额（分，可为负=欠费）');
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->string('type', 16)->comment('recharge|charge|refund');
            $table->bigInteger('amount_cents')->comment('正=入账，负=扣费');
            $table->bigInteger('balance_after_cents');
            $table->string('ref_type', 32)->nullable()->comment('关联对象（bill:123 等）');
            $table->string('remark')->default('');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
