<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortAllocation extends Model
{
    protected $fillable = ['server_id', 'port', 'allocated', 'tunnel_id', 'held_at'];

    protected $casts = [
        'port' => 'integer',
        'allocated' => 'boolean',
        'tunnel_id' => 'integer',
        'held_at' => 'datetime',
    ];

    public function tunnel(): BelongsTo
    {
        return $this->belongsTo(NpsTunnel::class, 'tunnel_id');
    }
}
