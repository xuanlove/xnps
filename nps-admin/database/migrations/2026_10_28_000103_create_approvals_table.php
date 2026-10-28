<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 审批单（DESIGN.md §8.1 状态机）：
//   submitted → approved → provisioning → provisioned
//   submitted → rejected / expired；provisioning → failed
// operation_key 幂等键：ProvisionApprovalJob 重试去重（防重复开通）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16)->comment('client|tunnel');
            $table->json('payload')->comment('申请内容');
            $table->string('status', 16)->default('submitted')->index();
            $table->string('operation_key', 64)->unique()->comment('幂等键（开通重试去重）');
            $table->unsignedBigInteger('approver_id')->nullable()->comment('审批人（自动审批为 null）');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->text('fail_reason')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
