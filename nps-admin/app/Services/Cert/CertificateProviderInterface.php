<?php

namespace App\Services\Cert;

/**
 * 证书来源层抽象（DESIGN.md §7.1 CertificateProvider）
 *
 * 实现方返回 [cert_pem, key_pem]；入库/校验/部署由 CertificateService 统一处理
 */
interface CertificateProviderInterface
{
    /**
     * 为给定域名签发证书
     *
     * @param  array  $domains  域名列表（通配符如 *.mgmt.example.com）
     * @return array{0: string, 1: string} [cert_pem, key_pem]
     */
    public function issue(array $domains): array;
}
