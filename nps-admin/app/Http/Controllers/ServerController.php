<?php

namespace App\Http\Controllers;

use App\Jobs\SyncVersionJob;
use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 服务器注册与管理（阶段 1：纳管入口）
 */
class ServerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NpsServer::orderBy('id')->get()->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'api_url' => $s->api_url,
                'bridge_type' => $s->bridge_type,
                'bridge_port' => $s->bridge_port,
                'nps_version' => $s->nps_version,
                'ext_version' => $s->ext_version,
                'status' => $s->status,
                'is_reachable' => $s->is_reachable,
                'change_seq' => $s->change_seq,
                'last_synced_seq' => $s->last_synced_seq,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
                'last_reconciled_at' => $s->last_reconciled_at?->toIso8601String(),
                'client_count' => $s->clients()->count(),
                'tunnel_count' => $s->tunnels()->count(),
                'host_count' => $s->hosts()->count(),
            ]),
        ]);
    }

    /**
     * 注册服务器：立即探活 + 首次全量对账 + 启动同步循环
     */
    public function store(Request $request, NpsApiClientFactory $factory, SyncEngine $syncEngine): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'api_url' => 'required|url',
            'auth_key' => 'required|string|min:8',
            'bridge_type' => 'nullable|in:tcp,kcp',
            'bridge_port' => 'nullable|integer|min:1|max:65535',
        ]);

        $server = NpsServer::create([
            'name' => $validated['name'],
            'api_url' => rtrim($validated['api_url'], '/'),
            'auth_key' => $validated['auth_key'],
            'bridge_type' => $validated['bridge_type'] ?? 'tcp',
            'bridge_port' => $validated['bridge_port'] ?? 8024,
        ]);

        // 探活 + 版本能力探测
        $resp = $factory->for($server)->extVersion();
        if (! $resp->ok) {
            $server->delete();

            return response()->json([
                'error' => 'nps server unreachable or auth failed: '.$resp->msg,
            ], 422);
        }

        // 首次全量对账（冷启动导入）
        $syncEngine->reconcile($server);

        // 启动同步循环
        SyncVersionJob::dispatch($server->id)->onQueue('nps-sync');
        \App\Jobs\SyncOnlineJob::dispatch($server->id)->onQueue('nps-sync');
        \App\Jobs\SyncFlowJob::dispatch($server->id)->onQueue('nps-sync');

        return response()->json(['data' => $server->fresh()], 201);
    }

    public function show(NpsServer $server): JsonResponse
    {
        return response()->json(['data' => $server]);
    }

    /**
     * 手动触发全量对账
     */
    public function sync(NpsServer $server, SyncEngine $syncEngine): JsonResponse
    {
        $syncEngine->reconcile($server);

        return response()->json([
            'data' => [
                'reconciled_at' => $server->fresh()->last_reconciled_at?->toIso8601String(),
                'clients' => $server->clients()->count(),
                'tunnels' => $server->tunnels()->count(),
                'hosts' => $server->hosts()->count(),
            ],
        ]);
    }

    /**
     * 读取 nps.conf（脱敏后的键值，AUDIT 白名单）
     */
    public function config(NpsServer $server, NpsApiClientFactory $factory): JsonResponse
    {
        $resp = $factory->for($server)->extConfigGet();
        if (! $resp->ok) {
            return response()->json(['error' => 'read config failed: '.$resp->msg], 502);
        }

        return response()->json(['data' => $resp->data]);
    }

    /**
     * 写入 nps.conf（白名单键；敏感值传 ****** 视为不修改），可联动重启
     */
    public function saveConfig(Request $request, NpsServer $server, NpsApiClientFactory $factory): JsonResponse
    {
        $validated = $request->validate([
            'entries' => 'required|array',
            'entries.*' => 'string|max:1024',
            'restart' => 'nullable|boolean',
        ]);

        $resp = $factory->for($server)->extConfigSet($validated['entries'], (bool) ($validated['restart'] ?? false));
        if (! $resp->ok) {
            return response()->json(['error' => 'write config failed: '.$resp->msg], 422);
        }

        if (! empty($validated['restart'])) {
            $server->forceFill(['is_reachable' => false])->saveQuietly();
        }

        return response()->json(['data' => $resp->data]);
    }

    public function restart(NpsServer $server, NpsApiClientFactory $factory): JsonResponse
    {
        $resp = $factory->for($server)->extRestart();
        if (! $resp->ok) {
            return response()->json(['error' => 'restart failed: '.$resp->msg], 502);
        }

        return response()->json(['data' => ['restart' => 'scheduled']], 202);
    }

    /**
     * 当前生效证书指纹（与证书库对账用）
     */
    public function cert(NpsServer $server, NpsApiClientFactory $factory): JsonResponse
    {
        $resp = $factory->for($server)->call('GET', '/ext/cert');
        if (! $resp->ok) {
            return response()->json(['error' => 'read certificate failed: '.$resp->msg], 502);
        }

        return response()->json(['data' => $resp->data]);
    }

    public function destroy(NpsServer $server): JsonResponse
    {
        // 停用即停止同步循环（Job 检查 isActive 自然终止）
        $server->update(['status' => 'disabled']);

        return response()->json(['data' => ['disabled' => true]]);
    }
}
