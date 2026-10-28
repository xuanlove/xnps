<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpsTunnel extends Model
{
    protected $table = 'nps_tunnels';

    protected $fillable = [
        'server_id', 'remote_id', 'client_id', 'client_remote_id',
        'port', 'mode', 'status', 'run_status', 'remark', 'target',
        'local_proxy', 'flow_in', 'flow_out',
    ];

    protected $casts = [
        'remote_id' => 'integer',
        'client_remote_id' => 'integer',
        'port' => 'integer',
        'status' => 'boolean',
        'run_status' => 'boolean',
        'local_proxy' => 'boolean',
        'flow_in' => 'integer',
        'flow_out' => 'integer',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class, 'server_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(NpsClient::class, 'client_id');
    }
}
