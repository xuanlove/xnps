<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 证书库（DESIGN.md §7.1）：Laravel 是唯一 SoT，certd 只做搬运
// key_pem 加密存储（encrypted cast）；cert_pem 公开
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->comment('显示名');
            $table->json('domains')->comment('覆盖域名（通配符或列表）');
            $table->text('cert_pem');
            $table->text('key_pem')->comment('私钥（encrypted cast）');
            $table->string('sha256', 64)->comment('证书指纹（对账键）');
            $table->timestamp('not_after')->comment('到期时间');
            $table->string('source', 16)->default('manual')->comment('manual|acme');
            $table->boolean('active')->default(true)->comment('当前生效版本');
            $table->timestamps();

            $table->index('not_after');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
