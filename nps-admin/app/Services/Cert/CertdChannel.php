<?php

namespace App\Services\Cert;

use App\Models\NpsServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * certd 部署通道（DESIGN.md §7.1 CertdChannel）
 *
 * 生产模式：Laravel 触发 certd 部署流水线，certd 以自定义 HTTP 部署插件
 * 将证书推到各 nps 的 /ext/cert（certd 只做搬运，Laravel 仍是唯一 SoT——
 * 部署结果回写本表，每日指纹对账校验一致性）。
 *
 * 未配置 certd 触发地址时自动回退 NativeChannel（兜底）。
 */
class CertdChannel implements DeploymentChannelInterface
{
    public function __construct(
        private readonly NativeChannel $native,
    ) {}

    public function deploy(NpsServer $server, string $certPem, string $keyPem, bool $autoRestart): array
    {
        $triggerUrl = (string) config('nps.certd.deploy_trigger_url');
        $token = (string) config('nps.certd.token');

        if ($triggerUrl === '') {
            // 未配置 certd：回退 native 直推
            Log::info('certd not configured, fallback to native channel');

            return $this->native->deploy($server, $certPem, $keyPem, $autoRestart);
        }

        // 触发 certd 流水线：目标为该 nps 的 /ext/cert（含签名由 certd 侧插件配置）
        $resp = Http::timeout(60)->withToken($token)->post($triggerUrl, [
            'server' => [
                'name' => $server->name,
                'api_url' => $server->api_url,
            ],
            'cert_pem_b64' => base64_encode($certPem),
            'key_pem_b64' => base64_encode($keyPem),
            'auto_restart' => $autoRestart,
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException('certd deploy trigger failed: HTTP '.$resp->status());
        }

        // certd 异步部署：立即返回占位结果，实际指纹由每日对账核验
        return ['sha256' => hash('sha256', $certPem), 'note' => 'certd pipeline triggered, verification deferred to daily reconcile'];
    }
}
