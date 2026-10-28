<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// nps client 镜像表：nps 是运行时权威，本表是管理平面的只读镜像 + 归属扩展
// (server_id, remote_id) 唯一——各 nps 的 id 空间独立（DESIGN.md §4.3）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('remote_id')->comment('nps 内 client id');
            $table->string('vkey', 64)->comment('客户端验证密钥（Laravel 统一生成）');
            $table->string('remark')->default('');
            $table->boolean('status')->default(true)->comment('启用状态');
            $table->boolean('is_connect')->default(false)->comment('在线状态（/ext/online 轮询）');
            $table->string('addr')->default('')->comment('最近连接 IP');
            $table->string('npc_version')->nullable();
            $table->unsignedInteger('rate_limit')->default(0)->comment('KB/S，0 不限');
            $table->unsignedInteger('max_conn')->default(0);
            $table->unsignedInteger('max_tunnel')->default(0);
            $table->unsignedBigInteger('flow_limit')->default(0)->comment('MB，生命周期累计（非月度，见 DESIGN §8.3）');
            $table->unsignedBigInteger('flow_in')->default(0);
            $table->unsignedBigInteger('flow_out')->default(0);
            $table->boolean('config_conn_allow')->default(false);
            $table->boolean('crypt')->default(false);
            $table->boolean('compress')->default(false);
            $table->string('basic_user')->default('')->comment('Basic Auth 用户名（密码不镜像）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('归属用户（P2 门户）');
            $table->timestamps();

            $table->unique(['server_id', 'remote_id']);
            $table->index('user_id');
            $table->index('is_connect');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_clients');
    }
};
