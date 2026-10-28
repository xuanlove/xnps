<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bill extends Model
{
    protected $fillable = [
        'user_id', 'period', 'plan_id', 'plan_fee_cents',
        'used_bytes', 'included_bytes', 'overage_bytes',
        'overage_fee_cents', 'total_fee_cents', 'status', 'paid_at',
    ];

    protected $casts = [
        'plan_fee_cents' => 'integer',
        'used_bytes' => 'integer',
        'included_bytes' => 'integer',
        'overage_bytes' => 'integer',
        'overage_fee_cents' => 'integer',
        'total_fee_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
