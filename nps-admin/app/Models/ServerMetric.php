<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id', 'cpu_percent', 'mem_used_percent', 'load1', 'disk_used_percent',
        'tcp_established', 'open_fds', 'client_online', 'goroutines', 'goroutines_peak',
        'collected_at',
    ];

    protected $casts = [
        'cpu_percent' => 'float',
        'mem_used_percent' => 'float',
        'load1' => 'float',
        'disk_used_percent' => 'float',
        'tcp_established' => 'integer',
        'open_fds' => 'integer',
        'client_online' => 'integer',
        'goroutines' => 'integer',
        'goroutines_peak' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }
}
