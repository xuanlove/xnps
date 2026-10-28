<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 升级制品（DESIGN.md §9.2 自建发布流水线）
 */
class UpgradeArtifact extends Model
{
    protected $table = 'upgrade_artifacts';

    protected $fillable = [
        'version', 'channel', 'sha256', 'filename',
        'size_bytes', 'compatible_core_version', 'notes', 'download_token',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected $hidden = ['download_token'];

    public function runs(): HasMany
    {
        return $this->hasMany(UpgradeRun::class, 'artifact_id');
    }

    public function absolutePath(): string
    {
        return storage_path('app/artifacts/'.$this->filename);
    }

    public function downloadUrl(): string
    {
        // 路径参数形式，与路由 /api/artifacts/{id}/download/{token} 一致
        return rtrim(config('app.url'), '/')."/api/artifacts/{$this->id}/download/{$this->download_token}";
    }
}
