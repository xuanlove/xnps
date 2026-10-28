<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upgrade_artifacts', function (Blueprint $table) {
            // 下载 token 过期时间（AUDIT L1）
            $table->timestamp('download_token_expires_at')->nullable()->after('download_token');
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_artifacts', function (Blueprint $table) {
            $table->dropColumn('download_token_expires_at');
        });
    }
};
