<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpgradeStep extends Model
{
    protected $table = 'upgrade_steps';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PRECHECKING = 'prechecking';

    public const STATUS_UPGRADING = 'upgrading';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'run_id', 'server_id', 'status', 'old_version', 'new_version',
        'precheck', 'result', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'precheck' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(UpgradeRun::class, 'run_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(NpsServer::class);
    }
}
