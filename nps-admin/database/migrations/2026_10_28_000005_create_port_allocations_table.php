<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 端口池：每服务器 allow_ports 区间的分配预占（DESIGN.md §4.5）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('port');
            $table->boolean('allocated')->default(false)->comment('false=预占中(带 TTL)，true=已绑定隧道');
            $table->unsignedBigInteger('tunnel_id')->nullable()->comment('绑定 nps_tunnels.id');
            $table->timestamp('held_at')->nullable()->comment('预占时间（TTL 超时自动释放）');
            $table->timestamps();

            $table->unique(['server_id', 'port']);
            $table->index(['server_id', 'allocated']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_allocations');
    }
};
