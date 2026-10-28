<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 审批单（DESIGN.md §8.1 状态机）
 *
 * submitted → approved → provisioning → provisioned
 * submitted → rejected / expired；provisioning → failed
 */
class Approval extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_PROVISIONED = 'provisioned';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const TYPE_CLIENT = 'client';

    public const TYPE_TUNNEL = 'tunnel';

    protected $fillable = [
        'user_id', 'type', 'payload', 'status',
        'operation_key', 'approver_id', 'reviewed_at',
        'provisioned_at', 'fail_reason', 'reject_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'reviewed_at' => 'datetime',
        'provisioned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
