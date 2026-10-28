<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 证书部署记录：每台 nps 的部署状态与指纹对账时间（DESIGN.md §7.2 双 SoT 防护）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_deployments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('certificate_id');
            $table->unsignedBigInteger('server_id');
            $table->string('channel', 16)->default('native')->comment('native|certd');
            $table->string('status', 16)->default('pending')->comment('pending|deployed|failed');
            $table->string('deployed_sha256', 64)->nullable()->comment('部署时证书指纹');
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('verified_at')->nullable()->comment('最近一次指纹对账通过时间');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['server_id']);
            $table->index('certificate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_deployments');
    }
};
