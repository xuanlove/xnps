<?php

namespace App\Services\Cert;

use App\Models\Certificate;
use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTPS 首次启用编排器（DESIGN.md §7.2 / §10）
 *
 * 防管理面自锁的关键顺序（编排器保证）：
 *   1. 推证书（/ext/cert，不重启）——此时 web_open_ssl 仍为 false
 *   2. 开 web_open_ssl=true（/ext/config 写 conf）
 *   3. 联动重启（/ext/restart）
 *   4. HTTPS 探活（/auth/gettime）
 *   5. 切换 server.api_url 到 https://（Laravel 侧后续调用走 TLS）
 *
 * 若顺序颠倒（先开开关后推证书），重启后 webapi 直接不可达——管理面自锁。
 */
class HttpsProvisioner
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
        private readonly CertificateService $certificateService,
    ) {}

    /**
     * 为一台服务器启用 HTTPS
     *
     * @param  bool  $tlsVerify 切换 https 后是否校验证书（M1：默认 true；自签需显式传 false 并告警）
     * @return array 编排结果
     */
    public function enable(NpsServer $server, Certificate $certificate, bool $tlsVerify = true): array
    {
        $steps = [];

        // 1. 推证书（不重启：web_open_ssl 还是 false，HTTP 仍可用）
        $this->certificateService->deploy($server, $certificate, 'native', false);
        $steps[] = '1. certificate deployed (no restart)';

        $api = $this->clientFactory->for($server);

        // 2. 开 web_open_ssl（写 conf，不热生效）
        $resp = $api->extConfigSet(['web_open_ssl' => 'true'], false);
        if (! $resp->ok) {
            throw new \RuntimeException('step 2 failed (ext/config): '.$resp->msg);
        }
        $steps[] = '2. web_open_ssl=true written to nps.conf';

        // 3. 联动重启
        $resp = $api->extRestart();
        if (! $resp->ok) {
            throw new \RuntimeException('step 3 failed (ext/restart): '.$resp->msg);
        }
        $steps[] = '3. restart triggered (202)';

        // 4. HTTPS 探活（等待重启完成，指数退避）
        $httpsUrl = $this->toHttps($server->api_url);
        $healthy = $this->waitHealthy($httpsUrl, $tlsVerify);
        if (! $healthy['ok']) {
            // 自锁恢复路径：回滚 web_open_ssl（此时 HTTP 也可能不可用——
            // 回滚需带外或等待 nps 重启后 HTTP 探测，记录告警由人工处理）
            \App\Models\Alert::create([
                'server_id' => $server->id,
                'type' => 'https_enable_failed',
                'level' => 'critical',
                'message' => 'HTTPS enable failed at health check: '.$healthy['error'],
                'context' => ['https_url' => $httpsUrl, 'steps' => $steps],
            ]);
            throw new \RuntimeException('step 4 failed (https health check): '.$healthy['error']);
        }
        $steps[] = '4. https health check passed';

        // 5. 切换 api_url + tls_verify
        $server->forceFill([
            'api_url' => $httpsUrl,
            'tls_verify' => $tlsVerify,
        ])->save();
        $steps[] = '5. api_url switched to https, tls_verify='.var_export($tlsVerify, true);

        Log::info("HTTPS enabled for server {$server->name}", ['steps' => $steps]);

        return ['steps' => $steps, 'api_url' => $httpsUrl];
    }

    /**
     * 证书轮换（已启用 HTTPS 的服务器）：部署新证书 + 重启 + 指纹核验
     *（滚动重启由调用方逐台控制，本方法只处理单台）
     */
    public function rotate(NpsServer $server, Certificate $certificate): array
    {
        $this->certificateService->deploy($server, $certificate, 'native', true);

        // 等待重启 + HTTPS 恢复
        $healthy = $this->waitHealthy($server->api_url, (bool) $server->tls_verify);
        if (! $healthy['ok']) {
            throw new \RuntimeException('rotate: https health check failed: '.$healthy['error']);
        }

        // 指纹核验
        $verify = $this->certificateService->verifyFingerprint($server);
        if (! $verify['ok']) {
            throw new \RuntimeException('rotate: fingerprint verify failed: '.$verify['message']);
        }

        return ['rotated' => true, 'sha256' => $certificate->sha256, 'verified' => true];
    }

    // ------------------------------------------------------------------

    private function toHttps(string $apiUrl): string
    {
        return preg_replace('#^http://#i', 'https://', $apiUrl);
    }

    /**
     * HTTPS 探活：/auth/gettime（免鉴权），指数退避最多 ~60s
     */
    private function waitHealthy(string $httpsUrl, bool $tlsVerify): array
    {
        $delays = [2, 3, 5, 8, 13, 21, 34]; // 秒，总计 ~86s
        foreach ($delays as $delay) {
            sleep($delay);
            try {
                $resp = Http::timeout(10)
                    ->withOptions(['verify' => $tlsVerify])
                    ->post(rtrim($httpsUrl, '/').'/auth/gettime');
                $body = $resp->json();
                if ($resp->successful() && isset($body['time'])) {
                    return ['ok' => true];
                }
            } catch (\Throwable $e) {
                // 继续退避
            }
        }

        return ['ok' => false, 'error' => 'https endpoint unreachable after ~86s'];
    }
}
