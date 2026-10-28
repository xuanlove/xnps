<?php

namespace App\Http\Controllers;

use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Services\Nps\NpsException;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * client 管理：镜像查询 + 开通/变更/删除（Saga）
 */
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NpsClient::with('server:id,name,api_url')
            ->when($request->integer('server_id'), fn ($q, $v) => $q->where('server_id', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where(fn ($w) => $w
                    ->where('remark', 'like', "%{$v}%")
                    ->orWhere('vkey', 'like', "%{$v}%"));
            })
            ->orderBy('id');

        return response()->json([
            'data' => $query->paginate(min(100, $request->integer('per_page', 20))),
        ]);
    }

    public function show(NpsClient $client): JsonResponse
    {
        return response()->json(['data' => $client->load('server:id,name,api_url', 'tunnels', 'hosts')]);
    }

    /**
     * 开通 client（vkey 由 Laravel 统一生成）
     */
    public function store(Request $request, ProvisioningService $provisioning): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'required|integer|exists:nps_servers,id',
            'remark' => 'nullable|string|max:255',
            'rate_limit' => 'nullable|integer|min:0',      // KB/S
            'flow_limit' => 'nullable|integer|min:0',      // MB（生命周期累计，非月度）
            'max_conn' => 'nullable|integer|min:0',
            'max_tunnel' => 'nullable|integer|min:0',
            'crypt' => 'nullable|boolean',
            'compress' => 'nullable|boolean',
            'config_conn_allow' => 'nullable|boolean',
            'basic_user' => 'nullable|string|max:64',
            'basic_pass' => 'nullable|string|max:64',
        ]);

        $server = NpsServer::findOrFail($validated['server_id']);
        if (! $server->isActive()) {
            return response()->json(['error' => 'server is disabled'], 422);
        }

        // nps API 参数映射（webapi.md）
        $params = [
            'remark' => $validated['remark'] ?? '',
            'rate_limit' => (string) ($validated['rate_limit'] ?? 0),
            'flow_limit' => (string) ($validated['flow_limit'] ?? 0),
            'max_conn' => (string) ($validated['max_conn'] ?? 0),
            'max_tunnel' => (string) ($validated['max_tunnel'] ?? 0),
            'crypt' => ! empty($validated['crypt']) ? 1 : 0,
            'compress' => ! empty($validated['compress']) ? 1 : 0,
            'config_conn_allow' => ! empty($validated['config_conn_allow']) ? 1 : 0,
            'u' => $validated['basic_user'] ?? '',
            'p' => $validated['basic_pass'] ?? '',
        ];

        try {
            $client = $provisioning->createClient($server, $params);
        } catch (NpsException|ProvisioningException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $client->fresh()], 201);
    }

    /**
     * 变更（含限速/配额调整——月度配额执行层见 BillingService，DESIGN.md §8.3）
     */
    public function update(Request $request, NpsClient $client, ProvisioningService $provisioning): JsonResponse
    {
        $validated = $request->validate([
            'remark' => 'nullable|string|max:255',
            'rate_limit' => 'nullable|integer|min:0',
            'flow_limit' => 'nullable|integer|min:0',
            'max_conn' => 'nullable|integer|min:0',
            'max_tunnel' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ]);

        $params = [];
        foreach (['remark', 'rate_limit', 'flow_limit', 'max_conn', 'max_tunnel'] as $key) {
            if (array_key_exists($key, $validated)) {
                $params[$key] = (string) $validated[$key];
            }
        }

        try {
            if (array_key_exists('status', $validated)) {
                // status=false 会立即踢下线（DESIGN.md §2.4-2）
                app(\App\Services\Nps\NpsApiClientFactory::class)
                    ->for($client->server)
                    ->changeClientStatus($client->remote_id, (bool) $validated['status'])
                    ->orFail('client/changestatus');
            }
            if (! empty($params)) {
                $provisioning->updateClient($client, $params);
            }
        } catch (NpsException|ProvisioningException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $client->fresh()]);
    }

    /**
     * 删除：nps 侧级联删除全部隧道与域名（DESIGN.md §2.4-1）
     */
    public function destroy(NpsClient $client, ProvisioningService $provisioning): JsonResponse
    {
        $impact = [
            'tunnels' => $client->tunnels()->count(),
            'hosts' => $client->hosts()->count(),
        ];

        try {
            $provisioning->deleteClient($client);
        } catch (NpsException|ProvisioningException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['deleted' => true, 'cascade' => $impact]]);
    }
}
