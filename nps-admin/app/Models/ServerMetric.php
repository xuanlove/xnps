<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'cpu_percent', 'mem_used_percent', 'load1',
        'tcp_established', 'client_online', 'goroutines', 'collected_at',
    ];

    protected $casts = [
        'cpu_percent' => 'float',
        'mem_used_percent' => 'float',
        'load1' => 'float',
        'tcp_established' => 'integer',
        'client_online' => 'integer',
        'goroutines' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }
}
