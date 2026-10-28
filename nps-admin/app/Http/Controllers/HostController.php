<?php

namespace App\Http\Controllers;

use App\Models\NpsClient;
use App\Models\NpsHost;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use App\Services\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 域名解析（host）管理：镜像查询 + 透传写 + 回读
 */
class HostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NpsHost::with('client:id,server_id,remote_id,vkey,remark', 'server:id,name')
            ->when($request->integer('server_id'), fn ($q, $v) => $q->where('server_id', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where(fn ($w) => $w
                    ->where('host', 'like', "%{$v}%")
                    ->orWhere('remark', 'like', "%{$v}%"));
            })
            ->orderBy('id');

        return response()->json(['data' => $query->paginate(min(100, $request->integer('per_page', 20)))]);
    }

    public function store(Request $request, NpsApiClientFactory $factory, SyncEngine $syncEngine): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:nps_clients,id',
            'host' => 'required|string|max:255',
            'target' => 'required|string|max:255',
            'scheme' => 'nullable|in:all,http,https',
            'location' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:255',
            'header' => 'nullable|string|max:1024',
            'hostchange' => 'nullable|string|max:255',
        ]);

        $client = NpsClient::with('server')->findOrFail($validated['client_id']);
        if (! $client->server->isActive()) {
            return response()->json(['error' => 'server is disabled'], 422);
        }

        $params = [
            'client_id' => $client->remote_id,
            'host' => $validated['host'],
            'target' => $validated['target'],
            'scheme' => $validated['scheme'] ?? 'all',
            'location' => $validated['location'] ?? '',
            'remark' => $validated['remark'] ?? '',
            'header' => $validated['header'] ?? '',
            'hostchange' => $validated['hostchange'] ?? '',
        ];

        try {
            $factory->for($client->server)->addHost($params)->orFail('index/addhost');
            // L1 回读
            $syncEngine->syncVersion($client->server);
        } catch (NpsException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $host = NpsHost::where('server_id', $client->server_id)
            ->where('client_id', $client->id)
            ->where('host', $validated['host'])
            ->orderByDesc('remote_id')->first();

        return response()->json(['data' => $host], 201);
    }

    public function destroy(NpsHost $host, NpsApiClientFactory $factory, SyncEngine $syncEngine): JsonResponse
    {
        try {
            $factory->for($host->server)->delHost($host->remote_id)->orFail('index/delhost');
            $syncEngine->syncVersion($host->server);
        } catch (NpsException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
        $host->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
