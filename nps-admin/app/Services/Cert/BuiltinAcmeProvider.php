<?php

namespace App\Services\Cert;

use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;

/**
 * 内置 ACME（nps 侧 x/crypto/acme + HTTP-01）
 *
 * 与 certd 模式的差异：签发动作在 nps 上完成，私钥在 nps 生成后随响应回传；
 * HTTP-01 挑战由 nps 的 HTTP 监听器（通常 80 端口）应答，因此要求该端口
 * 对公网可达、域名已解析到该服务器。通配符域名不支持（HTTP-01 限制）。
 */
class BuiltinAcmeProvider
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
    ) {}

    /**
     * @return array{0: string, 1: string} [cert_pem, key_pem]
     */
    public function issue(array $domains, NpsServer $server, string $email = ''): array
    {
        if (! $server->isActive()) {
            throw new \RuntimeException('builtin ACME requires an active nps server (http-01 challenge host)');
        }

        $resp = $this->clientFactory->for($server)->acmeIssue([
            'domains' => array_values($domains),
            'email' => $email,
            'directory_url' => (string) config('nps.acme.directory_url', ''),
        ]);

        try {
            $resp->orFail('ext/acme/issue');
        } catch (NpsException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $data = (array) $resp->data;
        $cert = (string) ($data['cert_pem'] ?? '');
        $key = (string) ($data['key_pem'] ?? '');
        if ($cert === '' || $key === '') {
            throw new \RuntimeException('builtin ACME returned empty cert/key');
        }

        return [$cert, $key];
    }
}
