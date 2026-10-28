<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 升级制品库（DESIGN.md §9.2）：自建发布流水线的产物登记。
// 官方 UpdateNps() 不可用（只拉 latest、无回滚、不含 ext 模块），
// 升级一律由本制品库 + UpgradeOrchestrator 驱动。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->comment('制品版本（如 0.26.10-ext0.1.1）');
            $table->string('channel', 16)->default('stable')->comment('stable|canary');
            $table->string('sha256', 64)->comment('二进制指纹（/ext/upgrade 强校验）');
            $table->string('filename')->comment('storage/app/artifacts 下的相对路径');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('compatible_core_version', 32)->default('0.26.0')->comment('兼容矩阵：npc core version（DESIGN.md §9.4）');
            $table->text('notes')->nullable()->comment('变更说明');
            $table->string('download_token', 64)->comment('下载端点令牌（nps 出方向拉取）');
            $table->timestamps();

            $table->unique('version');
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_artifacts');
    }
};
