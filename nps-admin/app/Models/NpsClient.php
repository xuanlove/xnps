<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * nps client 镜像：nps 是运行时权威，本表是管理平面镜像 + 归属扩展
 */
class NpsClient extends Model
{
    protected $table = 'nps_clients';

    protected $fillable = [
        'server_id', 'remote_id', 'vkey', 'remark', 'status', 'is_connect',
        'addr', 'npc_version', 'rate_limit', 'max_conn', 'max_tunnel',
        'flow_limit', 'flow_in', 'flow_out', 'config_conn_allow',
        'crypt', 'compress', 'basic_user', 'user_id',
    ];

    protected $casts = [
        'remote_id' => 'integer',
        'status' => 'boolean',
        'is_connect' => 'boolean',
        'rate_limit' => 'integer',
        'max_conn' => 'integer',
        'max_tunnel' => 'integer',
        'flow_limit' => 'integer',
        'flow_in' => 'integer',
        'flow_out' => 'integer',
        'config_conn_allow' => 'boolean',
        'crypt' => 'boolean',
        'compress' => 'boolean',
        'user_id' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class, 'server_id');
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(NpsTunnel::class, 'client_id');
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(NpsHost::class, 'client_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
