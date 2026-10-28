<?php

namespace App\Services\Cert;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ACME 自动签发来源（DESIGN.md §7.1 AcmeProvider）
 *
 * 生产部署两种对接模式（config nps.acme）：
 *   mode=certd  —— 调 certd 的签发 API（certd 负责 ACME DNS-01 全流程，
 *                  推荐通配符 *.mgmt.example.com，一张证书覆盖全部 nps 管理域名）
 *   mode=shell  —— 调用本机 acme.sh / certbot 命令产出证书文件
 *
 * 沙箱/内网环境无法完成真实 ACME 挑战（需公网域名 + DNS API），
 * 本实现为生产对接骨架：certd 模式完整实现 HTTP 调用；shell 模式留待部署时配置。
 */
class AcmeProvider implements CertificateProviderInterface
{
    public function issue(array $domains): array
    {
        $config = (array) config('nps.acme');

        if (($config['mode'] ?? '') === 'certd') {
            return $this->issueViaCertd($domains, $config);
        }

        throw new \RuntimeException(
            'ACME provider not configured: set NPS_ACME_MODE=certd with certd_url, '.
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
