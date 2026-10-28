<?php

namespace App\Services\Cert;

use App\Models\NpsServer;

/**
 * 证书部署通道抽象（DESIGN.md §7.1 DeploymentChannel）
 *
 * 所有通道最终收敛到唯一激活入口 POST /ext/cert（单一 SoT）
 */
interface DeploymentChannelInterface
{
    /**
     * 部署证书到目标服务器
     *
     * @param  bool  $autoRestart  部署后是否联动重启（滚动重启由编排器控制）
     * @return array /ext/cert 响应 data（含 sha256/not_after）
     */
    public function deploy(NpsServer $server, string $certPem, string $keyPem, bool $autoRestart): array;
}
