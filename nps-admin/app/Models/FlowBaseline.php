<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * client 流量采样快照（DESIGN.md §8.3）：
 * 每次采样记录 last_in/last_out，增量 = 当前 - 上次；
 * 若当前 < 上次（nps 重启清零），增量 = 当前（从 0 重新累计，防漏计/负数）
 */
class FlowBaseline extends Model
{
    protected $table = 'flow_baselines';

    protected $fillable = ['client_id', 'period', 'last_in', 'last_out'];

    protected $casts = [
        'client_id' => 'integer',
        'last_in' => 'integer',
        'last_out' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(NpsClient::class, 'client_id');
    }
}
