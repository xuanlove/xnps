<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nps 隧道镜像表
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_tunnels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('remote_id')->comment('nps 内 task id');
            $table->unsignedBigInteger('client_id')->nullable()->comment('本地镜像 nps_clients.id');
            $table->unsignedInteger('client_remote_id')->comment('nps 内 client id（变更日志只带此值）');
            $table->unsignedInteger('port')->default(0);
            $table->string('mode', 32)->default('tcp')->comment('tcp/udp/socks5/httpProxy/secret/p2p/file/tcpTrans');
            $table->boolean('status')->default(true)->comment('启用状态');
            $table->boolean('run_status')->default(false)->comment('运行状态（/ext/list 回读）');
            $table->string('remark')->default('');
            $table->string('target')->default('')->comment('内网目标 ip:port');
            $table->boolean('local_proxy')->default(false);
            $table->unsignedBigInteger('flow_in')->default(0);
            $table->unsignedBigInteger('flow_out')->default(0);
            $table->timestamps();

            $table->unique(['server_id', 'remote_id']);
            $table->index(['server_id', 'client_id']);
            $table->index(['server_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_tunnels');
    }
};
