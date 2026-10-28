<?php

namespace App\Services\Cert;

use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;

/**
 * Native 部署通道（DESIGN.md §7.1）：Laravel 直推 /ext/cert。
 * certd 故障时的兜底通道，也是阶段 3 端到端验证的主通道。
 */
class NativeChannel implements DeploymentChannelInterface
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    public function deploy(NpsServer $server, string $certPem, string $keyPem, bool $autoRestart): array
    {
        $resp = $this->clientFactory->for($server)->call('POST', '/ext/cert', [], [
            'cert_pem_b64' => base64_encode($certPem),
            'key_pem_b64' => base64_encode($keyPem),
            'restart' => $autoRestart,
        ])->orFail('ext/cert deploy');

        return (array) $resp->data;
    }
}
