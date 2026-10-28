<?php

namespace App\Services\Provisioning;

use App\Models\NpsClient;
use App\Models\NpsServer;
use App\Models\NpsTunnel;
use App\Models\PortAllocation;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Nps\NpsException;
use App\Services\Sync\SyncEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 写操作 Saga（DESIGN.md §4.4）：nps API 无事务，
 * 统一模式：本地 pending → 调 API → 回读确认 → 提交；失败补偿。
 *
 * 端口池（DESIGN.md §4.5）：(server_id, port) 唯一约束预占，
 * nps 侧 TestServerPort 试监听为第二道防线；TTL 超时自动释放。
 */
class ProvisioningService
{
    public function __construct(
        private readonly NpsApiClientFactory $clientFactory,
        private readonly SyncEngine $syncEngine,
    ) {}

    // ------------------------------------------------------------------
    // client
    // ------------------------------------------------------------------

    /**
     * 创建 client：vkey 由 Laravel 统一生成（跨服务器可控，DESIGN.md §2.4-7）
     *
     * @return NpsClient 本地镜像（已回读确认）
     * @throws NpsException|ProvisioningException
     */
    public function createClient(NpsServer $server, array $params): NpsClient
    {
        $vkey = $params['vkey'] ?? $this->generateVkey();
        $api = $this->clientFactory->for($server);

        $api->addClient(array_merge($params, ['vkey' => $vkey]))
            ->orFail('client/add');

        // L1 写后回读：拉增量确认（changelog hook 保证 add client 产生变更）
        $this->pullChanges($server);

        $client = NpsClient::where('server_id', $server->id)
            ->where('vkey', $vkey)->first();

        if (! $client) {
            throw new ProvisioningException('client created on nps but mirror not found after sync', ['vkey' => $vkey]);
        }

        return $client;
    }

    public function updateClient(NpsClient $client, array $params): void
    {
        $api = $this->clientFactory->for($client->server);
        $api->editClient($client->remote_id, $params)->orFail('client/edit');
        $this->pullChanges($client->server);
        $client->refresh();
    }

    /**
     * 删除 client：nps 侧级联删除其全部隧道与域名（DESIGN.md §2.4-1），
     * 调用方须先确认影响面
     */
    public function deleteClient(NpsClient $client): void
    {
        $server = $client->server;
        $api = $this->clientFactory->for($server);

        // 先收集端口绑定（pullChanges 后镜像隧道即被删除，届时查不到）
        $tunnelIds = NpsTunnel::where('client_id', $client->id)->pluck('id')->all();

        // 幂等：远端已不存在视为成功（del 对不存在 id 仍回 success）
        $api->delClient($client->remote_id)->orFail('client/del');
        $this->pullChanges($server);

        // 释放该 client 全部隧道的端口占用
        if (! empty($tunnelIds)) {
            PortAllocation::where('server_id', $server->id)
                ->whereIn('tunnel_id', $tunnelIds)
                ->delete();
        }

        NpsClient::where('id', $client->id)->delete();
    }

    // ------------------------------------------------------------------
    // tunnel
    // ------------------------------------------------------------------

    /**
     * 创建隧道（Saga）：
     *   1. 端口池预占（事务 + 唯一约束）
     *   2. 调 /index/add
     *   3. 回读确认（run_status 校验——nps 的 start 会虚报，DESIGN.md §2.4-3）
     *   4. 绑定端口占用
     *   5. 失败补偿：delTunnel + 释放预占
     *
     * @return NpsTunnel
     */
    public function createTunnel(NpsClient $client, array $params): NpsTunnel
    {
        $server = $client->server;
        $api = $this->clientFactory->for($server);

        $mode = $params['mode'] ?? 'tcp';
        $needsPort = ! in_array($mode, ['secret', 'p2p'], true);

        // secret/p2p 无监听端口，跳过端口池
        $heldPort = null;
        if ($needsPort) {
            $port = $params['port'] ?? null;
            // 自动分配时排除 nps 自身监听端口（AUDIT M4）
            $reserved = $port === null ? $this->reservedPorts($server) : [];
            $heldPort = $this->holdPort($server, $port, [], $reserved);
            $params['port'] = $heldPort;
        }

        try {
            $api->addTunnel(array_merge([
                'client_id' => $client->remote_id,
                'type' => $mode,
                'remark' => $params['remark'] ?? '',
                'target' => $params['target'] ?? '',
            ], $params))->orFail('index/add');

            // L1 回读确认
            $this->pullChanges($server);
            $tunnel = NpsTunnel::where('server_id', $server->id)
                ->where('client_id', $client->id)
                ->where('port', $heldPort ?? 0)
                ->where('mode', $mode)
                ->orderByDesc('remote_id')
                ->first();

            if (! $tunnel) {
                throw new ProvisioningException('tunnel created on nps but mirror not found after sync', $params);
            }

            // run_status 校验：nps 的 add/start 会虚报成功（端口实际未监听）
            if ($needsPort && ! $tunnel->run_status) {
                throw new ProvisioningException(
                    "tunnel {$tunnel->remote_id} created but not running (port {$heldPort} may be occupied)",
                    ['tunnel_id' => $tunnel->remote_id],
                );
            }

            // 提交：绑定端口占用
            if ($heldPort !== null) {
                PortAllocation::where('server_id', $server->id)
                    ->where('port', $heldPort)
                    ->update(['allocated' => true, 'tunnel_id' => $tunnel->id, 'held_at' => null]);
            }

            return $tunnel;
        } catch (\Throwable $e) {
            // 补偿：删除半成品隧道 + 释放端口预占
            $this->compensateTunnel($server, $heldPort);
            throw $e;
        }
    }

    public function deleteTunnel(NpsTunnel $tunnel): void
    {
        $server = $tunnel->server;
        $api = $this->clientFactory->for($server);

        $api->delTunnel($tunnel->remote_id)->orFail('index/del');
        $this->pullChanges($server);

        // 释放端口占用
        if ($tunnel->port > 0) {
            PortAllocation::where('server_id', $server->id)
                ->where('port', $tunnel->port)
                ->delete();
        }
        $tunnel->delete();
    }

    // ------------------------------------------------------------------
    // 端口池
    // ------------------------------------------------------------------

    /**
     * 端口预占：指定端口或从 allow_ports 区间自动分配。
     * (server_id, port) 唯一约束是防 TOCTOU 的第一道防线。
     *
     * @param  array  $reservedPorts  自动分配时跳过的端口（nps 自身监听端口，AUDIT M4）
     * @return int 分配到的端口
     * @throws ProvisioningException 无可用端口
     */
    public function holdPort(NpsServer $server, ?int $preferred = null, array $allowRanges = [], array $reservedPorts = []): int
    {
        // 先清理过期预占（TTL）
        PortAllocation::where('server_id', $server->id)
            ->where('allocated', false)
            ->where('held_at', '<', now()->subSeconds((int) config('nps.port_hold_ttl')))
            ->delete();

        $tryHold = function (int $port) use ($server): ?int {
            try {
                PortAllocation::create([
                    'server_id' => $server->id,
                    'port' => $port,
                    'allocated' => false,
                    'held_at' => now(),
                ]);

                return $port;
            } catch (\Throwable) {
                return null; // 唯一约束冲突：已被占用
            }
        };

        if ($preferred !== null) {
            $held = $tryHold($preferred);
            if ($held !== null) {
                return $held;
            }
            throw new ProvisioningException("port {$preferred} is already allocated on server {$server->name}");
        }

        // 自动分配：默认区间 10000-65535（生产应按 nps.conf allow_ports 配置）
        $reserved = array_flip($reservedPorts);
        $ranges = ! empty($allowRanges) ? $allowRanges : [[10000, 65535]];
        foreach ($ranges as [$min, $max]) {
            // 随机起点降低连续冲突概率
            $span = $max - $min + 1;
            $start = $min + random_int(0, max(0, $span - 1));
            for ($i = 0; $i < $span; $i++) {
                $port = $min + (($start - $min + $i) % $span);
                if (isset($reserved[$port])) {
                    continue; // nps 自身监听端口，跳过
                }
                $held = $tryHold($port);
                if ($held !== null) {
                    return $held;
                }
            }
        }

        throw new ProvisioningException("no available port on server {$server->name}");
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * L1 写后回读：拉取增量变更并应用（幂等，可安全重试）
     */
    public function pullChanges(NpsServer $server): void
    {
        $api = $this->clientFactory->for($server);
        $resp = $api->extVersion();
        if ($resp->ok) {
            $data = (array) $resp->data;
            $remoteSeq = (int) ($data['change_seq'] ?? 0);
            if ($remoteSeq > $server->last_synced_seq) {
                $this->syncEngine->syncChanges($server, $api, $server->last_synced_seq);
            }
            if ($remoteSeq < $server->last_synced_seq) {
                // nps 重启：全量对账
                $this->syncEngine->reconcile($server);
            }
            $server->forceFill([
                'change_seq' => $remoteSeq,
                'last_synced_seq' => $remoteSeq,
                'last_synced_at' => now(),
            ])->saveQuietly();
        }
    }

    /**
     * 补偿：删除远端半成品隧道（按端口定位）+ 释放端口预占
     */
    private function compensateTunnel(NpsServer $server, ?int $port): void
    {
        try {
            if ($port !== null) {
                $api = $this->clientFactory->for($server);
                // 按端口找远端隧道删除（镜像可能尚未同步）
                $resp = $api->extList('tunnel', 0, 1000);
                if ($resp->ok) {
                    $data = (array) $resp->data;
                    foreach ((array) ($data['items'] ?? []) as $item) {
                        if ((int) ($item['port'] ?? -1) === $port) {
                            $api->delTunnel((int) $item['id']);
                        }
                    }
                }
                PortAllocation::where('server_id', $server->id)
                    ->where('port', $port)
                    ->where('allocated', false)
                    ->delete();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('tunnel compensation failed: '.$e->getMessage(), [
                'server' => $server->id, 'port' => $port,
            ]);
        }
    }

    private function generateVkey(): string
    {
        // 与 nps 的 crypt.GetRandomString(16) 等价长度；查重防撞
        do {
            $vkey = strtolower(Str::random(16));
        } while (NpsClient::where('vkey', $vkey)->exists());

        return $vkey;
    }

    /**
     * nps 自身监听端口集合：bridge_port + /ext/config 中的端口类键
     * （自动分配时排除，避免撞 nps 已监听端口，AUDIT M4）。
     * 读取失败不阻塞分配——run_status 回读校验仍为兜底防线。
     */
    private function reservedPorts(NpsServer $server): array
    {
        $ports = [];
        if ($server->bridge_port > 0) {
            $ports[] = (int) $server->bridge_port;
        }

        try {
            $resp = $this->clientFactory->for($server)->extConfigGet();
            if ($resp->ok) {
                $listenKeys = ['bridge_port', 'web_port', 'p2p_port', 'http_proxy_port', 'https_proxy_port'];
                foreach ((array) ($resp->data['entries'] ?? []) as $entry) {
                    if (in_array($entry['key'] ?? '', $listenKeys, true)) {
                        $value = (int) ($entry['value'] ?? 0);
                        if ($value > 0) {
                            $ports[] = $value;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug(
                "reserved ports fetch failed for server {$server->id}: {$e->getMessage()}",
            );
        }

        return array_values(array_unique($ports));
    }
}
