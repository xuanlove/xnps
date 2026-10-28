<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpsApiLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'method', 'endpoint', 'request', 'response',
        'status_code', 'duration_ms', 'ok', 'error', 'created_at',
    ];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'ok' => 'boolean',
        'created_at' => 'datetime',
    ];
}
