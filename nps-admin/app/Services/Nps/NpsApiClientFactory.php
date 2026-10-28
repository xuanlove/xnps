<?php

namespace App\Services\Nps;

use App\Models\NpsServer;

/**
 * NpsApiClient 工厂：按服务器模型构造客户端实例
 */
class NpsApiClientFactory
{
    public function for(NpsServer $server): NpsApiClient
    {
        return new NpsApiClient($server);
    }

    public function forId(int $serverId): NpsApiClient
    {
        $server = NpsServer::findOrFail($serverId);

        return $this->for($server);
    }
}
