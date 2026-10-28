<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 月度配额执行层（DESIGN.md §8.3）：
//   flow_monthly   用户月累计用量（增量累加，防 nps 重启清零漏计）
//   flow_baselines client 上次采样快照（兼作月度基线）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_monthly', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('period', 7)->comment('账期 YYYY-MM');
            $table->unsignedBigInteger('used_in')->default(0);
            $table->unsignedBigInteger('used_out')->default(0);
            $table->string('quota_action', 16)->nullable()->comment('已执行的超额动作 throttle|disable');
            $table->timestamp('quota_action_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });

        Schema::create('flow_baselines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->comment('本地镜像 nps_clients.id');
            $table->string('period', 7);
            $table->unsignedBigInteger('last_in')->default(0)->comment('上次采样 flow_in 快照');
            $table->unsignedBigInteger('last_out')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_baselines');
        Schema::dropIfExists('flow_monthly');
    }
};
