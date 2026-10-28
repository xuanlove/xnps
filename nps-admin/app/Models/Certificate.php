<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 证书库（DESIGN.md §7.1）：Laravel 是唯一 SoT
 */
class Certificate extends Model
{
    protected $fillable = [
        'name', 'domains', 'cert_pem', 'key_pem',
        'sha256', 'not_after', 'source', 'active',
    ];

    protected $casts = [
        'domains' => 'array',
        'key_pem' => 'encrypted',
        'not_after' => 'datetime',
        'active' => 'boolean',
    ];

    protected $hidden = ['key_pem'];

    public function deployments(): HasMany
    {
        return $this->hasMany(CertificateDeployment::class);
    }

    /**
     * 距到期天数
     */
    public function daysUntilExpiry(): int
    {
        return (int) ceil(now()->diffInDays($this->not_after, false));
    }
}
