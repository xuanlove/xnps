<?php

namespace App\Services\Cert;

use App\Models\NpsServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 证书签发来源路由（DESIGN.md §7.1 AcmeProvider）
 *
 * 两种对接模式（config nps.acme.mode）：
 *   certd   —— 调 certd 的签发 API（certd 负责 ACME DNS-01 全流程，
 *              推荐通配符 *.mgmt.example.com，一张证书覆盖全部 nps 管理域名）
 *   builtin —— 内置 ACME（Let's Encrypt），nps 侧 HTTP-01 签发；
 *              需指定一台 active nps 作为挑战应答主机，不支持通配符
 */
class AcmeProvider implements CertificateProviderInterface
{
    public function __construct(
        private readonly BuiltinAcmeProvider $builtin,
    ) {}

    public function issue(array $domains, ?NpsServer $server = null, ?string $mode = null): array
    {
        $config = (array) config('nps.acme');
        $mode = $mode ?? (string) ($config['mode'] ?? '');

        if ($mode === 'certd') {
            return $this->issueViaCertd($domains, $config);
        }
        if ($mode === 'builtin') {
            $server ??= NpsServer::where('status', 'active')->orderBy('id')->first();
            if (! $server) {
                throw new \RuntimeException('builtin ACME requires an active nps server');
            }

            return $this->builtin->issue($domains, $server, (string) ($config['email'] ?? ''));
        }

        throw new \RuntimeException(
            'ACME provider not configured: set NPS_ACME_MODE=certd|builtin, '.
            'or use manual upload (CertificateService::import)'
        );
    }

    /**
     * certd 模式：调 certd 签发流水线，轮询取回证书
     */
    private function issueViaCertd(array $domains, array $config): array
    {
        $url = (string) ($config['certd_url'] ?? '');
        $token = (string) ($config['certd_token'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('nps.acme.certd_url is required for certd mode');
        }

        // 触发签发流水线（certd 的流水线 API，具体字段以部署的 certd 版本为准）
        $resp = Http::timeout(60)->withToken($token)->post(rtrim($url, '/').'/api/cert/apply', [
            'domains' => $domains,
            'challenge' => 'dns-01',
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException('certd apply failed: HTTP '.$resp->status());
        }
        $flowId = $resp->json('id') ?? $resp->json('data.id');
        if (! $flowId) {
            throw new \RuntimeException('certd apply returned no flow id');
        }

        // 轮询流水线结果（DNS-01 传播 + CA 签发，通常 1-5 分钟）
        $deadline = now()->addMinutes(10);
        while (now()->lt($deadline)) {
            sleep(10);
            $status = Http::timeout(30)->withToken($token)
                ->get(rtrim($url, '/')."/api/cert/{$flowId}");
            if ($status->successful() && ($status->json('status') === 'issued')) {
                $cert = $status->json('cert_pem');
                $key = $status->json('key_pem');
                if (is_string($cert) && is_string($key)) {
                    return [$cert, $key];
                }
            }
            Log::debug("certd flow {$flowId} still pending");
        }

        throw new \RuntimeException("certd flow {$flowId} timed out after 10 minutes");
    }
}
