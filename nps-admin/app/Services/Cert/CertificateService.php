<?php

namespace App\Services\Cert;

use App\Models\Alert;
use App\Models\Certificate;
use App\Models\CertificateDeployment;
use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use Illuminate\Support\Facades\Log;

/**
 * 证书子系统核心（DESIGN.md §7）：证书库 + 部署 + 指纹对账 + 到期检查
 *
 * Laravel 是唯一 SoT：certd/native 通道只做搬运，
 * 每日对账校验各 nps 实际证书指纹（GET /ext/cert）与库内登记一致。
 */
class CertificateService
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
        private readonly NativeChannel $nativeChannel,
        private readonly CertdChannel $certdChannel,
    ) {}

    // ------------------------------------------------------------------
    // 证书入库
    // ------------------------------------------------------------------

    /**
     * 人工上传导入（ManualProvider 路径）
     *
     * @return Certificate
     */
    public function import(string $name, string $certPem, string $keyPem): Certificate
    {
        $meta = $this->validateAndParse($certPem, $keyPem);

        return Certificate::create([
            'name' => $name,
            'domains' => $meta['domains'],
            'cert_pem' => $certPem,
            'key_pem' => $keyPem,
            'sha256' => $meta['sha256'],
            'not_after' => $meta['not_after'],
            'source' => 'manual',
            'active' => true,
        ]);
    }

    /**
     * ACME 自动签发入库（certd / 内置 ACME 两种来源）
     *
     * @param  string|null  $mode  certd|builtin（空=config nps.acme.mode）
     */
    public function issueAcme(array $domains, ?NpsServer $server = null, ?string $mode = null): Certificate
    {
        $provider = app(AcmeProvider::class);
        [$certPem, $keyPem] = $provider->issue($domains, $server, $mode);
        $meta = $this->validateAndParse($certPem, $keyPem);
        $mode = $mode ?? (string) config('nps.acme.mode');

        return Certificate::create([
            'name' => implode(',', $domains),
            'domains' => $domains,
            'cert_pem' => $certPem,
            'key_pem' => $keyPem,
            'sha256' => $meta['sha256'],
            'not_after' => $meta['not_after'],
            'source' => $mode === 'builtin' ? 'acme-builtin' : 'acme',
            'active' => true,
        ]);
    }

    /**
     * 校验证书与私钥匹配 + 解析元信息（域名/到期/指纹）
     */
    public function validateAndParse(string $certPem, string $keyPem): array
    {
        $cert = openssl_x509_read($certPem);
        if ($cert === false) {
            throw new \InvalidArgumentException('invalid cert pem: '.openssl_error_string());
        }
        $key = openssl_pkey_get_private($keyPem);
        if ($key === false) {
            throw new \InvalidArgumentException('invalid key pem: '.openssl_error_string());
        }
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw new \InvalidArgumentException('cert and key do not match');
        }

        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            throw new \InvalidArgumentException('cannot parse cert: '.openssl_error_string());
        }

        $domains = [];
        foreach ((array) ($parsed['subject']['CN'] ?? []) as $cn) {
            $domains[] = (string) $cn;
        }
        foreach ((array) ($parsed['extensions']['subjectAltName'] ?? []) as $san) {
            foreach (explode(',', (string) $san) as $entry) {
                $entry = trim($entry);
                if (str_starts_with($entry, 'DNS:')) {
                    $domains[] = substr($entry, 4);
                }
            }
        }

        return [
            'domains' => array_values(array_unique($domains)),
            'not_after' => \Carbon\Carbon::createFromTimestampUTC($parsed['validTo_time_t']),
            'sha256' => hash('sha256', $certPem),
        ];
    }

    // ------------------------------------------------------------------
    // 部署
    // ------------------------------------------------------------------

    /**
     * 部署当前生效证书到一台服务器（记录 deployment）
     *
     * @param  string  $channel  native|certd
     * @param  bool  $autoRestart  部署后联动重启（滚动重启由编排器逐台控制）
     */
    public function deploy(NpsServer $server, Certificate $certificate, string $channel = 'native', bool $autoRestart = false): CertificateDeployment
    {
        $deployment = CertificateDeployment::updateOrCreate(
            ['server_id' => $server->id],
            [
                'certificate_id' => $certificate->id,
                'channel' => $channel,
                'status' => 'pending',
                'error' => null,
            ],
        );

        try {
            $deployer = $channel === 'certd' ? $this->certdChannel : $this->nativeChannel;
            $result = $deployer->deploy($server, $certificate->cert_pem, $certificate->key_pem, $autoRestart);

            $deployment->update([
                'status' => 'deployed',
                'deployed_sha256' => $result['sha256'] ?? $certificate->sha256,
                'deployed_at' => now(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $deployment->fresh();
    }

    // ------------------------------------------------------------------
    // 指纹对账（DESIGN.md §7.2 双 SoT 防护）
    // ------------------------------------------------------------------

    /**
     * 校验一台服务器实际生效证书与部署记录一致。
     *
     * @return array{ok: bool, remote: array|null, message: string}
     */
    public function verifyFingerprint(NpsServer $server): array
    {
        $resp = $this->clientFactory->for($server)->call('GET', '/ext/cert');
        if (! $resp->ok) {
            return ['ok' => false, 'remote' => null, 'message' => 'ext/cert query failed: '.$resp->msg];
        }
        $remote = (array) $resp->data;
        $remoteSha = (string) ($remote['sha256'] ?? '');

        $deployment = CertificateDeployment::where('server_id', $server->id)->first();
        if (! $deployment || $deployment->status !== 'deployed') {
            return ['ok' => false, 'remote' => $remote, 'message' => 'no deployed certificate record for this server'];
        }

        if ($remoteSha !== $deployment->deployed_sha256) {
            return ['ok' => false, 'remote' => $remote, 'message' => sprintf(
                'fingerprint mismatch: deployed=%s remote=%s（外部直改证书？）',
                substr($deployment->deployed_sha256, 0, 12), substr($remoteSha, 0, 12),
            )];
        }

        $deployment->update(['verified_at' => now()]);

        return ['ok' => true, 'remote' => $remote, 'message' => 'fingerprint verified'];
    }

    /**
     * 全平台对账（scheduler 每日）：逐台校验 + 失配告警
     *
     * @return array{servers: int, ok: int, mismatch: int}
     */
    public function reconcileAll(): array
    {
        $stats = ['servers' => 0, 'ok' => 0, 'mismatch' => 0];
        foreach (NpsServer::where('status', 'active')->get() as $server) {
            $stats['servers']++;
            $result = $this->verifyFingerprint($server);
            if ($result['ok']) {
                $stats['ok']++;
            } else {
                $stats['mismatch']++;
                \App\Models\Alert::create([
                    'server_id' => $server->id,
                    'type' => 'cert_mismatch',
                    'level' => 'warning',
                    'message' => $result['message'],
                    'context' => $result['remote'],
                ]);
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // 到期检查（DESIGN.md §7.2：到期前 30 天自动续）
    // ------------------------------------------------------------------

    /**
     * @return Certificate[] 即将到期（<= renew_days）的证书
     */
    public function expiringSoon(): array
    {
        $deadline = now()->addDays((int) config('nps.cert.renew_days', 30));

        return Certificate::where('active', true)
            ->where('not_after', '<=', $deadline)
            ->get()->all();
    }

    // ------------------------------------------------------------------
    // 自动续期（P1-2）
    // ------------------------------------------------------------------

    /**
     * 续期所有即将到期的 ACME 证书，并滚动部署到原有目标服务器。
     * manual 来源不自动续签（由 MonitoringService 告警，人工上传）。
     *
     * @return array{renewed: int, deployed: int, failed: int, skipped: int}
     */
    public function renewExpiring(): array
    {
        $stats = ['renewed' => 0, 'deployed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->expiringSoon() as $cert) {
            if (! in_array($cert->source, ['acme', 'acme-builtin'], true)) {
                $stats['skipped']++; // manual：仅告警
                continue;
            }
            if ($this->hasNewerCertificate($cert)) {
                $stats['skipped']++;
                continue;
            }

            $mode = $cert->source === 'acme-builtin' ? 'builtin' : 'certd';
            $server = $this->renewalServer($cert, $mode);

            try {
                $new = $this->issueAcme((array) $cert->domains, $server, $mode);
                $stats['renewed']++;
                $cert->update(['active' => false]);
                Log::info("certificate renewed: #{$cert->id} -> #{$new->id} ({$mode})");
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->raiseRenewAlert($server, $cert, 'certificate renew failed: '.$e->getMessage(), ['error' => $e->getMessage()]);
                Log::error("certificate renew failed #{$cert->id}: {$e->getMessage()}");
                continue;
            }

            // 按原部署记录滚动替换（autoRestart=true 使新证书生效）
            $deployments = CertificateDeployment::where('certificate_id', $cert->id)->with('server')->get();
            foreach ($deployments as $deployment) {
                $target = $deployment->server;
                if (! $target || ! $target->isActive()) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    $this->deploy($target, $new, $deployment->channel ?: 'native', true);
                    $stats['deployed']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $this->raiseRenewAlert($target, $new, 'certificate redeploy failed: '.$e->getMessage(), [
                        'error' => $e->getMessage(),
                        'channel' => $deployment->channel,
                    ]);
                    Log::error("certificate redeploy failed #{$new->id} on server {$target->id}: {$e->getMessage()}");
                }
            }
        }

        return $stats;
    }

    private function hasNewerCertificate(Certificate $cert): bool
    {
        $candidates = Certificate::where('active', true)
            ->where('id', '!=', $cert->id)
            ->where('not_after', '>', $cert->not_after)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->sameDomains((array) $candidate->domains, (array) $cert->domains)) {
                return true;
            }
        }

        return false;
    }

    private function sameDomains(array $a, array $b): bool
    {
        $a = array_values(array_unique(array_map(fn ($d) => strtolower((string) $d), $a)));
        $b = array_values(array_unique(array_map(fn ($d) => strtolower((string) $d), $b)));
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function renewalServer(Certificate $cert, string $mode): ?NpsServer
    {
        if ($mode !== 'builtin') {
            return null; // certd 由 DNS-01 完成，不依赖某台 nps
        }
        $deployment = CertificateDeployment::where('certificate_id', $cert->id)
            ->with('server')->first();
        if ($deployment && $deployment->server && $deployment->server->isActive()) {
            return $deployment->server;
        }

        return NpsServer::where('status', 'active')->orderBy('id')->first();
    }

    private function raiseRenewAlert(?NpsServer $server, Certificate $cert, string $message, array $context = []): void
    {
        Alert::create([
            'server_id' => $server?->id,
            'type' => 'cert_renew_failed',
            'level' => 'critical',
            'message' => $message,
            'context' => $context + [
                'certificate_id' => $cert->id,
                'domains' => $cert->domains,
            ],
            'created_at' => now(),
        ]);
    }
}
