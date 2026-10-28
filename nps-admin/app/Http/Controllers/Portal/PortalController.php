<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Services\Portal\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2 自助门户（DESIGN.md §8.4）：
 * 用户可见：自己的 client/vkey/在线/流量/隧道、npc 安装命令；
 * 用户可操作：受套餐与审批约束的开通申请
 */
class PortalController extends Controller
{
    /**
     * 我的 client 列表
     */
    public function clients(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->npsClients()
                ->with('server:id,name,api_url,bridge_type,bridge_port', 'tunnels')
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * client 详情（含 vkey 与 npc 安装命令）
     */
    public function clientShow(Request $request, NpsClient $client): JsonResponse
    {
        $this->authorize('view', $client);
        $client->load('server:id,name,api_url,bridge_type,bridge_port', 'tunnels', 'hosts');

        return response()->json([
            'data' => $client,
            'npc_setup' => [
                'install' => 'curl -sL https://ehang.io/nps/install | sh',
                'run' => sprintf(
                    'npc -server=%s:%d -vkey=%s',
                    parse_url($client->server->api_url, PHP_URL_HOST) ?? $client->server->api_url,
                    $client->server->bridge_port,
                    $client->vkey,
                ),
            ],
        ]);
    }

    /**
     * 提交开通 client 申请（DESIGN.md §8.1：submitted → 自动审批或人工）
     */
    public function requestClient(Request $request, ApprovalService $approvalService): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'nullable|integer|exists:nps_servers,id',
            'remark' => 'nullable|string|max:255',
            'crypt' => 'nullable|boolean',
            'compress' => 'nullable|boolean',
        ]);

        // 默认第一台 active 服务器
        $serverId = $validated['server_id']
            ?? NpsServer::where('status', 'active')->value('id');
        if (! $serverId) {
            return response()->json(['error' => 'no active nps server available'], 422);
        }

        $approval = $approvalService->submit($request->user(), Approval::TYPE_CLIENT, [
            'server_id' => (int) $serverId,
            'remark' => $validated['remark'] ?? '',
            'crypt' => ! empty($validated['crypt']),
            'compress' => ! empty($validated['compress']),
        ]);

        return response()->json(['data' => $approval], 201);
    }

    /**
     * 提交开通隧道申请
     */
    public function requestTunnel(Request $request, NpsClient $client, ApprovalService $approvalService): JsonResponse
    {
        $this->authorize('manage', $client);

        $validated = $request->validate([
            'mode' => 'required|in:tcp,udp,socks5,httpProxy,secret,p2p',
            'target' => 'required_unless:mode,socks5,secret,p2p|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'remark' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:64',
        ]);

        $approval = $approvalService->submit($request->user(), Approval::TYPE_TUNNEL, [
            'client_id' => $client->id,
            'mode' => $validated['mode'],
            'target' => $validated['target'] ?? '',
            'port' => $validated['port'] ?? null,
            'remark' => $validated['remark'] ?? '',
            'password' => $validated['password'] ?? '',
        ]);

        return response()->json(['data' => $approval], 201);
    }

    /**
     * 我的申请列表
     */
    public function approvals(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->approvals()->orderByDesc('id')->paginate(50),
        ]);
    }

    /**
     * 我的账单
     */
    public function bills(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->bills()->orderByDesc('period')->paginate(50),
        ]);
    }
}
