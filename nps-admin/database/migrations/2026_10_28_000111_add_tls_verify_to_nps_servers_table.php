<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nps_servers 扩展：TLS 校验开关（自签证书场景 false，生产 CA 签发 true）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nps_servers', function (Blueprint $table) {
            $table->boolean('tls_verify')->default(true)->after('api_url');
        });
    }

    public function down(): void
    {
        Schema::table('nps_servers', function (Blueprint $table) {
            $table->dropColumn('tls_verify');
        });
    }
};
