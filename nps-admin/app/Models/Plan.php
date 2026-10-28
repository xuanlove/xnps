<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'monthly_fee_cents', 'max_tunnels',
        'monthly_flow_mb', 'rate_limit_kb', 'max_conn', 'active',
    ];

    protected $casts = [
        'monthly_fee_cents' => 'integer',
        'max_tunnels' => 'integer',
        'monthly_flow_mb' => 'integer',
        'rate_limit_kb' => 'integer',
        'max_conn' => 'integer',
        'active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }
}
