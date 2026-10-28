<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\UpgradeRunJob;
use App\Models\UpgradeArtifact;
use App\Models\UpgradeRun;
use App\Services\Upgrade\UpgradeOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 版本滚动升级管理（DESIGN.md §9，阶段 4）：
 * 制品上传 / 列表 / 启动升级（canary+批次）/ 状态查询 / 二进制下载端点
 */
class UpgradeController extends Controller
{
    // ------------------------------------------------------------------
    // 制品库
    // ------------------------------------------------------------------

    public function artifacts(): JsonResponse
    {
        return response()->json([
            'data' => UpgradeArtifact::orderByDesc('id')->get(),
        ]);
    }

    /**
     * 上传制品（CI 产物或手动构建的二进制）
     */
    public function artifactStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:upgrade_artifacts,version'],
            'channel' => 'nullable|in:stable,canary',
            'compatible_core_version' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:1024',
            'binary' => 'required|file|max:262144', // 256MB
        ]);

        $file = $request->file('binary');
        $sha256 = hash_file('sha256', $file->getRealPath());

        $filename = 'artifacts/'.$validated['version'].'/nps';
        Storage::putFileAs(
            dirname($filename),
            $file,
            basename($filename),
        );

        $artifact = UpgradeArtifact::create([
            'version' => $validated['version'],
            'channel' => $validated['channel'] ?? 'stable',
            'sha256' => $sha256,
            'filename' => $filename,
            'size_bytes' => $file->getSize(),
            'compatible_core_version' => $validated['compatible_core_version'] ?? '0.26.0',
            'notes' => $validated['notes'] ?? '',
            'download_token' => Str::random(48),
        ]);

        return response()->json(['data' => $artifact], 201);
    }

    // ------------------------------------------------------------------
    // 升级编排
    // ------------------------------------------------------------------

    /**
     * 启动升级：默认异步（dispatch Job）；?sync=1 同步执行（小批量/测试）
     */
    public function start(Request $request, UpgradeArtifact $artifact, UpgradeOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'server_ids' => 'required|array|min:1',
            'server_ids.*' => 'integer|exists:nps_servers,id',
            'canary_server_id' => 'nullable|integer|exists:nps_servers,id',
            'observe_seconds' => 'nullable|integer|min:0|max:900',
            'sync' => 'nullable|boolean',
        ]);

        if ($request->boolean('sync')) {
            $run = $orchestrator->start(
                $artifact,
                $validated['server_ids'],
                $validated['canary_server_id'] ?? null,
                (int) ($validated['observe_seconds'] ?? 900),
            );
        } else {
            $run = UpgradeRun::create([
                'artifact_id' => $artifact->id,
                'server_ids' => $validated['server_ids'],
                'canary_server_id' => $validated['canary_server_id'] ?? $validated['server_ids'][0],
                'observe_seconds' => (int) ($validated['observe_seconds'] ?? 900),
                'status' => UpgradeRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);
            foreach ($validated['server_ids'] as $sid) {
                \App\Models\UpgradeStep::create(['run_id' => $run->id, 'server_id' => $sid, 'status' => 'pending']);
            }
            UpgradeRunJob::dispatch($run->id)->onQueue('nps-upgrade');
        }

        return response()->json(['data' => $run->load('steps')], 201);
    }

    /**
     * 升级状态：run + steps + 汇总
     */
    public function show(UpgradeRun $run): JsonResponse
    {
        return response()->json([
            'data' => $run->load(['artifact:id,version,sha256,channel', 'steps.server:id,name']),
        ]);
    }

    public function runs(): JsonResponse
    {
        return response()->json([
            'data' => UpgradeRun::with('artifact:id,version,channel')
                ->orderByDesc('id')->limit(50)->get(),
        ]);
    }
}
