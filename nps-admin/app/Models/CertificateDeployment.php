<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateDeployment extends Model
{
    protected $fillable = [
        'certificate_id', 'server_id', 'channel', 'status',
        'deployed_sha256', 'deployed_at', 'verified_at', 'error',
    ];

    protected $casts = [
        'deployed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }
}
