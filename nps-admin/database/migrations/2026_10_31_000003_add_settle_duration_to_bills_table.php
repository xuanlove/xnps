<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// I3：月度结算耗时（/metrics 暴露 nps_billing_settle_duration_seconds）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedInteger('settle_duration_ms')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('settle_duration_ms');
        });
    }
};
