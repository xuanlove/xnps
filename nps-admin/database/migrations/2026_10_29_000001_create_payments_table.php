<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('channel', 16)->comment('alipay|wechat|mock');
            $table->string('out_trade_no', 64)->unique();
            $table->string('subject')->default('账户充值');
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 16)->default('pending')->comment('pending|paid|failed|expired');
            $table->text('qr_code')->nullable()->comment('扫码支付二维码内容');
            $table->text('pay_url')->nullable()->comment('跳转支付链接（如有）');
            $table->string('transaction_id', 128)->nullable()->index()->comment('渠道交易号');
            $table->json('notify_payload')->nullable()->comment('回调原文（脱敏后）');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
