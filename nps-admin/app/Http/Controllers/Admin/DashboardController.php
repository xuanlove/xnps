<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Bill;
use App\Models\NpsClient;
use App\Models\NpsHost;
use App\Models\NpsServer;
use App\Models\NpsTunnel;
use App\Models\Payment;
use App\Models\ServerMetric;
use Illuminate\Http\JsonResponse;

/**
 * 管理端仪表盘聚合（单请求返回首屏所需数据，避免 UI 串行请求）
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $servers = NpsServer::orderBy('id')->get();
        $activeServers = $servers->where('status', 'active');

        $serverRows = $servers->map(function (NpsServer $s) {
            $metric = ServerMetric::where('server_id', $s->id)->orderByDesc('collected_at')->first();

            return [
                'id' => $s->id,
                'name' => $s->name,
                'api_url' => $s->api_url,
                'status' => $s->status,
                'is_reachable' => $s->is_reachable,
                'nps_version' => $s->nps_version,
                'ext_version' => $s->ext_version,
                'last_synced_at' => $s->last_synced_at?->toIso8601String(),
                'last_reconciled_at' => $s->last_reconciled_at?->toIso8601String(),
                'client_count' => NpsClient::where('server_id', $s->id)->count(),
                'tunnel_count' => NpsTunnel::where('server_id', $s->id)->count(),
                'host_count' => NpsHost::where('server_id', $s->id)->count(),
                'client_online' => $metric?->client_online ?? 0,
                'cpu_percent' => $metric?->cpu_percent,
                'mem_used_percent' => $metric?->mem_used_percent,
                'sync_lag_seconds' => $s->last_synced_at ? now()->diffInSeconds($s->last_synced_at, true) : null,
            ];
        });

        return response()->json([
            'data' => [
                'stats' => [
                    'servers' => $servers->count(),
                    'servers_active' => $activeServers->count(),
                    'servers_unreachable' => $servers->where('is_reachable', false)->count(),
                    'clients' => NpsClient::count(),
                    'clients_online' => NpsClient::where('is_connect', true)->count(),
                    'tunnels' => NpsTunnel::count(),
                    'tunnels_running' => NpsTunnel::where('run_status', true)->count(),
                    'hosts' => NpsHost::count(),
                    'approvals_pending' => Approval::whereIn('status', ['submitted', 'approved', 'provisioning'])->count(),
                    'alerts_24h' => Alert::where('created_at', '>', now()->subDay())->count(),
                    'bills_unpaid' => Bill::where('status', 'unpaid')->count(),
                    'payments_paid_30d_cents' => (int) Payment::where('status', 'paid')
                        ->where('paid_at', '>', now()->subDays(30))->sum('amount_cents'),
                ],
                'servers' => $serverRows,
                'alerts' => Alert::orderByDesc('id')->limit(10)->get(['id', 'server_id', 'type', 'level', 'message', 'created_at']),
                'approvals' => Approval::with('user:id,name,email')->whereIn('status', ['submitted', 'approved', 'provisioning'])
                    ->orderByDesc('id')->limit(10)->get(['id', 'user_id', 'type', 'status', 'payload', 'created_at']),
            ],
        ]);
    }
}
