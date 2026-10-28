<?php

namespace App\Services\Cert;

/**
 * 人工上传来源（DESIGN.md §7.1 ManualProvider）
 *
 * 证书由运维在管理后台上传（含私钥），经 CertificateService 校验后入库
 */
class ManualProvider implements CertificateProviderInterface
{
    public function issue(array $domains): array
    {
        // ManualProvider 不主动签发：证书来自上传接口（CertificateService::import）
        throw new \LogicException('manual provider does not issue; use CertificateService::import()');
    }
}
