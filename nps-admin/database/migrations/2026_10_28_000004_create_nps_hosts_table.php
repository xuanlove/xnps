<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nps 域名解析（host）镜像表
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('remote_id')->comment('nps 内 host id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedInteger('client_remote_id');
            $table->string('host')->comment('域名');
            $table->string('location')->default('/');
            $table->string('scheme', 16)->default('all')->comment('all/http/https');
            $table->string('remark')->default('');
            $table->boolean('is_close')->default(false);
            $table->string('target')->default('');
            $table->unsignedBigInteger('flow_in')->default(0);
            $table->unsignedBigInteger('flow_out')->default(0);
            $table->timestamps();

            $table->unique(['server_id', 'remote_id']);
            $table->index(['server_id', 'client_id']);
            $table->index('host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_hosts');
    }
};
