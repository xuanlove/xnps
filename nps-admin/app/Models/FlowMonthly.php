<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户月度用量（DESIGN.md §8.3 月度配额执行层）
 */
class FlowMonthly extends Model
{
    protected $table = 'flow_monthly';

    protected $fillable = [
        'user_id', 'period', 'used_in', 'used_out',
        'quota_action', 'quota_action_at',
    ];

    protected $casts = [
        'used_in' => 'integer',
        'used_out' => 'integer',
        'quota_action_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usedBytes(): int
    {
        return $this->used_in + $this->used_out;
    }
}
