<?php

namespace App\Http\Controllers;

use App\Models\NpsClient;
use App\Models\NpsTunnel;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 隧道管理：镜像查询 + Saga 开通/删除 + 启停（含 run_status 回读校验）
 */
class TunnelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NpsTunnel::with('client:id,server_id,remote_id,vkey,remark', 'server:id,name')
            ->when($request->integer('server_id'), fn ($q, $v) => $q->where('server_id', $v))
            ->when($request->integer('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->input('mode'), fn ($q, $v) => $q->where('mode', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('remark', 'like', "%{$v}%"))
            ->orderBy('id');

        return response()->json(['data' => $query->paginate(min(100, $request->integer('per_page', 20)))]);
    }

    /**
     * 开通隧道：端口池预占 → /index/add → 回读 run_status 校验（DESIGN.md §4.4/§4.5）
     */
    public function store(Request $request, ProvisioningService $provisioning): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:nps_clients,id',
            'mode' => 'required|in:tcp,udp,socks5,httpProxy,secret,p2p,file',
            'target' => 'required_unless:mode,socks5,secret,p2p|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535', // 空=端口池自动分配
            'remark' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:64',        // secret/p2p 专用
            'local_proxy' => 'nullable|boolean',
        ]);

        $client = NpsClient::with('server')->findOrFail($validated['client_id']);
        if (! $client->server->isActive()) {
            return response()->json(['error' => 'server is disabled'], 422);
        }

        $params = [
            'mode' => $validated['mode'],
            'target' => $validated['target'] ?? '',
            'remark' => $validated['remark'] ?? '',
            'password' => $validated['password'] ?? '',
            'local_proxy' => ! empty($validated['local_proxy']) ? 1 : 0,
        ];
        if (! empty($validated['port'])) {
            $params['port'] = (int) $validated['port'];
        }

        try {
            $tunnel = $provisioning->createTunnel($client, $params);
        } catch (NpsException|ProvisioningException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $tunnel->fresh(['client:id,remote_id,vkey', 'server:id,name'])], 201);
    }

    /**
     * 启动：nps 的 /index/start 会虚报成功（DESIGN.md §2.4-3），
     * 启动后必须回读 run_status 校验
     */
    public function start(NpsTunnel $tunnel, NpsApiClientFactory $factory): JsonResponse
    {
        $api = $factory->for($tunnel->server);
        try {
            $api->startTunnel($tunnel->remote_id)->orFail('index/start');

            // 回读校验
            $resp = $api->extList('tunnel', $tunnel->remote_id - 1, 1);
            if ($resp->ok) {
                $data = (array) $resp->data;
                $items = (array) ($data['items'] ?? []);
                foreach ($items as $item) {
                    if ((int) $item['id'] === $tunnel->remote_id) {
                        $tunnel->update([
                            'run_status' => (bool) ($item['run_status'] ?? false),
                            'status' => (bool) ($item['status'] ?? false),
                        ]);
                    }
                }
            }
        } catch (NpsException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if (! $tunnel->fresh()->run_status) {
            return response()->json([
                'error' => "tunnel {$tunnel->remote_id} reported started but run_status=false (port may be occupied)",
                'data' => $tunnel->fresh(),
            ], 502);
        }

        return response()->json(['data' => $tunnel->fresh()]);
    }

    public function stop(NpsTunnel $tunnel, NpsApiClientFactory $factory): JsonResponse
    {
        try {
            $factory->for($tunnel->server)->stopTunnel($tunnel->remote_id)->orFail('index/stop');
        } catch (NpsException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
        $tunnel->update(['run_status' => false, 'status' => false]);

        return response()->json(['data' => $tunnel->fresh()]);
    }

    public function destroy(NpsTunnel $tunnel, ProvisioningService $provisioning): JsonResponse
    {
        try {
            $provisioning->deleteTunnel($tunnel);
        } catch (NpsException|ProvisioningException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }
}
