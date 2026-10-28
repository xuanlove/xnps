<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 升级编排（DESIGN.md §9.3）：一次 run 覆盖一批服务器（canary + 滚动批次）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('artifact_id');
            $table->json('server_ids')->comment('计划升级的服务器');
            $table->unsignedInteger('canary_server_id')->nullable()->comment('灰度首台');
            $table->unsignedInteger('observe_seconds')->default(900)->comment('canary 观察窗口（秒）');
            $table->string('status', 16)->default('running')->comment('running|succeeded|failed|aborted');
            $table->json('summary')->nullable()->comment('汇总报告（各台前后版本/耗时/回滚）');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('upgrade_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('server_id');
            $table->string('status', 16)->default('pending')->comment('pending|prechecking|upgrading|verifying|succeeded|failed|rolled_back');
            $table->string('old_version', 64)->nullable();
            $table->string('new_version', 64)->nullable();
            $table->json('precheck')->nullable()->comment('预检结果（npc 扫描/conf 快照路径）');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_steps');
        Schema::dropIfExists('upgrade_runs');
    }
};
