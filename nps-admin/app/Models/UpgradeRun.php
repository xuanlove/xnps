<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpgradeRun extends Model
{
    protected $table = 'upgrade_runs';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'artifact_id', 'server_ids', 'canary_server_id',
        'observe_seconds', 'status', 'summary', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'server_ids' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(UpgradeArtifact::class, 'artifact_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(UpgradeStep::class, 'run_id');
    }
}
