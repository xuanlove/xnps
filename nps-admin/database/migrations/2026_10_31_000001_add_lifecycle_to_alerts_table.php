<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// I3：告警生命周期（确认 / 自动/手动解决 / webhook 通知重试记录）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('status', 16)->default('open')->after('level')->comment('open|acknowledged|resolved');
            $table->timestamp('acknowledged_at')->nullable()->after('status');
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');
            $table->timestamp('resolved_at')->nullable()->after('acknowledged_by');
            $table->timestamp('notified_at')->nullable()->after('resolved_at');
            $table->unsignedTinyInteger('notify_attempts')->default(0)->after('notified_at');
            $table->string('notify_error')->nullable()->after('notify_attempts');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'acknowledged_at', 'acknowledged_by',
                'resolved_at', 'notified_at', 'notify_attempts', 'notify_error',
            ]);
        });
    }
};
