<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// I3：告警静默（server/type 为空表示全局或全部类型）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_silences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id')->nullable()->comment('空=全部服务器');
            $table->string('type', 32)->nullable()->comment('空=全部告警类型');
            $table->string('reason', 255)->default('');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('until');
            $table->timestamps();

            $table->index(['server_id', 'type', 'until']);
            $table->index('until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_silences');
    }
};
