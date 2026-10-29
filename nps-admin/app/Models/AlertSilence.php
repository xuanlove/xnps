<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 告警静默（I3）：server_id/type 为空表示匹配全部。
 */
class AlertSilence extends Model
{
    protected $fillable = [
        'server_id', 'type', 'reason', 'created_by', 'until',
    ];

    protected $casts = [
        'until' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->until !== null && $this->until->isFuture();
    }
}
