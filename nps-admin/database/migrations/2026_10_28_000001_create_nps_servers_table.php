<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nps 服务器注册表：集中管理平面纳管的每一台 nps 实例
// auth_key 加密存储（Laravel encrypted cast），用户永不接触
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('服务器显示名');
            $table->string('api_url')->comment('webapi 基址，如 https://nps-01.example.com');
            $table->text('auth_key')->comment('nps.conf auth_key（encrypted cast）');
            $table->string('bridge_type')->default('tcp');
            $table->unsignedInteger('bridge_port')->default(8024);
            $table->string('nps_version')->nullable()->comment('nps 版本（/ext/version 探测）');
            $table->string('ext_version')->nullable()->comment('nps-admin-ext 版本');
            $table->unsignedBigInteger('change_seq')->default(0)->comment('最近观测到的变更序号');
            $table->unsignedBigInteger('last_synced_seq')->default(0)->comment('已同步到的变更序号');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('status')->default('active')->comment('active|disabled');
            $table->boolean('is_reachable')->default(false)->comment('最近一次 API 探活结果');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_servers');
    }
};
