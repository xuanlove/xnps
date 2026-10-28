<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\NpsServer;
use App\Models\ServerMetric;
use App\Services\Monitor\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 监控与告警（阶段 3）：指标查询 / 告警列表 / 手动触发检查
 */
class MonitorController extends Controller
{
    /**
     * 服务器指标（最新一条 + 历史曲线）
     */
    public function metrics(Request $request, NpsServer $server): JsonResponse
    {
        $hours = min(168, $request->integer('hours', 24));

        return response()->json([
            'data' => [
                'server' => $server->only(['id', 'name', 'is_reachable', 'nps_version', 'client_online']),
                'latest' => ServerMetric::where('server_id', $server->id)
                    ->orderByDesc('collected_at')->first(),
                'history' => ServerMetric::where('server_id', $server->id)
                    ->where('collected_at', '>', now()->subHours($hours))
                    ->orderBy('collected_at')
                    ->get(['cpu_percent', 'mem_used_percent', 'load1', 'tcp_established', 'client_online', 'collected_at']),
            ],
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $query = Alert::with('server:id,name')
            ->when($request->input('level'), fn ($q, $v) => $q->where('level', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('server_id'), fn ($q, $v) => $q->where('server_id', $v))
            ->orderByDesc('id');

        return response()->json(['data' => $query->paginate(min(200, $request->integer('per_page', 50)))]);
    }

    /**
     * 手动触发全量告警检查（scheduler 每 5 分钟自动执行）
     */
    public function check(MonitoringService $monitoring): JsonResponse
    {
        $count = $monitoring->checkAll();

        return response()->json(['data' => ['new_alerts' => $count]]);
    }
}
