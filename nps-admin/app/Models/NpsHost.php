<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpsHost extends Model
{
    protected $table = 'nps_hosts';

    protected $fillable = [
        'server_id', 'remote_id', 'client_id', 'client_remote_id',
        'host', 'location', 'scheme', 'remark', 'is_close', 'target',
        'flow_in', 'flow_out',
    ];

    protected $casts = [
        'remote_id' => 'integer',
        'client_remote_id' => 'integer',
        'is_close' => 'boolean',
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
