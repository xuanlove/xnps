<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const CHANNEL_ALIPAY = 'alipay';

    public const CHANNEL_WECHAT = 'wechat';

    public const CHANNEL_MOCK = 'mock';

    protected $fillable = [
        'user_id', 'bill_id', 'channel', 'out_trade_no', 'subject',
        'amount_cents', 'status', 'qr_code', 'pay_url', 'transaction_id',
        'notify_payload', 'paid_at', 'expires_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'bill_id' => 'integer',
        'notify_payload' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
