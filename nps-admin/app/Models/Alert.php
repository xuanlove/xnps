<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'type', 'level', 'message', 'context', 'notified', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'notified' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }
}
