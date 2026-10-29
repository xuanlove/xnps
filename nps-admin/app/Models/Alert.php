<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /** 服务器级告警类型（用于条件恢复后的自动解决） */
    public const SERVER_TYPES = ['unreachable', 'cpu_high', 'mem_high', 'disk_high', 'sync_lag', 'tunnel_down'];

    public $timestamps = false;

    protected $fillable = [
        'server_id', 'type', 'level', 'status', 'message', 'context',
        'acknowledged_at', 'acknowledged_by', 'resolved_at',
        'notified', 'notified_at', 'notify_attempts', 'notify_error', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'notified' => 'boolean',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
        'notify_attempts' => 'integer',
        'created_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
