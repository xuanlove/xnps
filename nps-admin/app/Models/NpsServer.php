<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * nps 服务器注册（集中管理平面的纳管对象）
 */
class NpsServer extends Model
{
    protected $fillable = [
        'name', 'api_url', 'auth_key', 'bridge_type', 'bridge_port',
        'nps_version', 'ext_version', 'change_seq', 'last_synced_seq',
        'last_synced_at', 'last_reconciled_at', 'status', 'is_reachable',
    ];

    // auth_key 绝不出现在任何 API 响应中（AUDIT C1）
    protected $hidden = [
        'auth_key',
    ];

    protected $casts = [
        'auth_key' => 'encrypted',
        'bridge_port' => 'integer',
        'change_seq' => 'integer',
        'last_synced_seq' => 'integer',
        'last_synced_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'is_reachable' => 'boolean',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(NpsClient::class, 'server_id');
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(NpsTunnel::class, 'server_id');
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(NpsHost::class, 'server_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
