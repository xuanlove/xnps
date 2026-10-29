<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertSilence;
use App\Models\NpsServer;
use App\Models\ServerMetric;
use App\Services\Monitor\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 监控与告警（阶段 3 / I3）：指标查询 / 告警列表 / 确认 / 解决 / 静默 / 手动检查
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
                    ->get([
                        'cpu_percent', 'mem_used_percent', 'load1', 'disk_used_percent',
                        'tcp_established', 'open_fds', 'client_online', 'goroutines',
                        'goroutines_peak', 'collected_at',
                    ]),
            ],
        ]);
    }

    /**
     * 告警列表（状态/级别/类型/服务器过滤）
     */
    public function alerts(Request $request): JsonResponse
    {
        $query = Alert::with('server:id,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('level'), fn ($q, $v) => $q->where('level', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('server_id'), fn ($q, $v) => $q->where('server_id', $v))
            ->orderByDesc('id');

        return response()->json(['data' => $query->paginate(min(200, $request->integer('per_page', 50)))]);
    }

    /**
     * 确认告警（值班认领；不改变触发条件）
     */
    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        if ($alert->status === Alert::STATUS_RESOLVED) {
            return response()->json(['error' => 'alert already resolved'], 422);
        }

        $alert->update([
            'status' => Alert::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $alert->fresh('server')]);
    }

    /**
     * 手动解决告警（条件恢复时由 MonitoringService 自动 resolve）
     */
    public function resolve(Alert $alert): JsonResponse
    {
        $alert->update([
            'status' => Alert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        return response()->json(['data' => $alert->fresh('server')]);
    }

    /**
     * 创建静默：server_id/type 为空分别表示全部服务器/全部类型
     */
    public function silence(Request $request): JsonResponse
    {
        $types = array_merge(Alert::SERVER_TYPES, ['cert_expiring']);
        $validated = $request->validate([
            'server_id' => 'nullable|integer|exists:nps_servers,id',
            'type' => 'nullable|in:'.implode(',', $types),
            'minutes' => 'required|integer|min:1|max:10080',
            'reason' => 'nullable|string|max:255',
        ]);

        $silence = AlertSilence::create([
            'server_id' => $validated['server_id'] ?? null,
            'type' => $validated['type'] ?? null,
            'reason' => $validated['reason'] ?? '',
            'created_by' => $request->user()?->id,
            'until' => now()->addMinutes((int) $validated['minutes']),
        ]);

        return response()->json(['data' => $silence->load('server:id,name')], 201);
    }

    /**
     * 静默列表（默认仅未过期）
     */
    public function silences(Request $request): JsonResponse
    {
        $query = AlertSilence::with('server:id,name')->orderBy('until');
        if (! $request->boolean('include_expired')) {
            $query->where('until', '>', now());
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * 取消静默（立即恢复告警）
     */
    public function unsilence(AlertSilence $silence): JsonResponse
    {
        $silence->delete();

        return response()->json(['data' => ['deleted' => true]]);
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
