<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// API 审计日志：全部写操作必记；读操作按 config('nps.audit_reads') 可选记录
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('method', 8);
            $table->string('endpoint');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('ok')->default(false);
            $table->string('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_api_logs');
    }
};
