<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\NpsServer;
use App\Services\Cert\CertificateService;
use App\Services\Cert\HttpsProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 证书管理（DESIGN.md §7）：上传 / 部署 / HTTPS 启用 / 轮换 / 对账
 */
class CertificateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Certificate::orderByDesc('id')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'domains' => $c->domains,
                'source' => $c->source,
                'sha256' => substr($c->sha256, 0, 16).'...',
                'not_after' => $c->not_after?->toIso8601String(),
                'days_until_expiry' => $c->daysUntilExpiry(),
                'active' => $c->active,
                'deployments' => $c->deployments()->with('server:id,name')->get(),
            ]),
        ]);
    }

    /**
     * 人工上传证书（ManualProvider 路径）：校验匹配后入库
     */
    public function store(Request $request, CertificateService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'cert_pem' => 'required|string',
            'key_pem' => 'required|string',
        ]);

        try {
            $cert = $service->import($validated['name'], $validated['cert_pem'], $validated['key_pem']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $cert], 201);
    }

    /**
     * ACME 签发（certd 或内置 ACME）并入库
     */
    public function issue(Request $request, CertificateService $service): JsonResponse
    {
        $validated = $request->validate([
            'domains' => 'required|array|min:1|max:10',
            'domains.*' => 'string|max:253',
            'mode' => 'required|in:certd,builtin',
            'server_id' => 'nullable|integer|exists:nps_servers,id',
        ]);

        $server = ! empty($validated['server_id'])
            ? NpsServer::find($validated['server_id'])
            : null;

        try {
            $cert = $service->issueAcme($validated['domains'], $server, $validated['mode']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $cert], 201);
    }

    /**
     * 部署证书到一台服务器（不重启；滚动重启由编排器逐台控制）
     */
    public function deploy(Request $request, Certificate $certificate, NpsServer $server, CertificateService $service): JsonResponse
    {
        $channel = $request->input('channel', 'native');
        if (! in_array($channel, ['native', 'certd'], true)) {
            return response()->json(['error' => 'channel must be native or certd'], 422);
        }

        try {
            $deployment = $service->deploy($server, $certificate, $channel, false);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $deployment]);
    }

    /**
     * HTTPS 首次启用编排（防自锁顺序，DESIGN.md §10）
     */
    public function enableHttps(Request $request, Certificate $certificate, NpsServer $server, HttpsProvisioner $provisioner): JsonResponse
    {
        // M1：默认校验证书；自签证书需调用方显式传 tls_verify=false，并留告警
        $tlsVerify = $request->has('tls_verify') ? $request->boolean('tls_verify') : true;
        if (! $tlsVerify) {
            Log::warning('https enabled with tls_verify=false (self-signed certificate?)', [
                'server' => $server->name,
                'certificate' => $certificate->name,
            ]);
        }

        try {
            $result = $provisioner->enable($server, $certificate, $tlsVerify);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 证书轮换（已启用 HTTPS）：部署 + 重启 + 指纹核验
     */
    public function rotate(Certificate $certificate, NpsServer $server, HttpsProvisioner $provisioner): JsonResponse
    {
        try {
            $result = $provisioner->rotate($server, $certificate);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * 全平台指纹对账
     */
    public function verifyAll(CertificateService $service): JsonResponse
    {
        return response()->json(['data' => $service->reconcileAll()]);
    }

    /**
     * 指纹对账（单台或全平台）
     */
    public function verify(Request $request, NpsServer $server, CertificateService $service): JsonResponse
    {
        if ($request->boolean('all')) {
            return response()->json(['data' => $service->reconcileAll()]);
        }

        return response()->json(['data' => $service->verifyFingerprint($server)]);
    }
}
