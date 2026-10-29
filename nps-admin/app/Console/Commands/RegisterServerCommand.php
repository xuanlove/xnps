<?php

namespace App\Console\Commands;

use App\Jobs\SyncFlowJob;
use App\Jobs\SyncOnlineJob;
use App\Jobs\SyncVersionJob;
use App\Models\NpsServer;
use App\Services\Nps\NpsApiClientFactory;
use App\Services\Sync\SyncEngine;
use Illuminate\Console\Command;

/**
 * 注册并纳管一台 nps 服务器：探活 → 首次全量对账 → 启动同步循环
 */
class RegisterServerCommand extends Command
{
    protected $signature = 'nps:register
        {name : 服务器显示名}
        {api_url : webapi 基址，如 http://1.2.3.4:8080}
        {auth_key : nps.conf 的 auth_key}
        {--bridge-port=8024 : bridge 端口}
        {--bridge-type=tcp : bridge 类型 tcp|kcp}';

    protected $description = '注册并纳管一台 nps 服务器（探活 + 首次对账 + 启动同步）';

    public function handle(NpsApiClientFactory $factory, SyncEngine $syncEngine): int
    {
        // 幂等：同名服务器重复注册时更新连接参数（compose init 可安全重跑）
        $server = NpsServer::firstOrNew(['name' => $this->argument('name')]);
        $server->fill([
            'api_url' => rtrim($this->argument('api_url'), '/'),
            'auth_key' => $this->argument('auth_key'),
            'bridge_type' => $this->option('bridge-type'),
            'bridge_port' => (int) $this->option('bridge-port'),
        ])->save();
        $created = $server->wasRecentlyCreated;

        $resp = $factory->for($server)->extVersion();
        if (! $resp->ok) {
            if ($created) {
                $server->delete();
            }
            $this->error("unreachable or auth failed: {$resp->msg}");

            return self::FAILURE;
        }

        $data = (array) $resp->data;
        $server->forceFill([
            'nps_version' => (string) ($data['version'] ?? ''),
            'ext_version' => (string) ($data['ext_version'] ?? ''),
            'change_seq' => (int) ($data['change_seq'] ?? 0),
            'is_reachable' => true,
        ])->saveQuietly();
        $this->info(sprintf(
            'connected: nps %s (core %s), ext %s, change_seq=%d',
            $data['version'] ?? '?', $data['core_version'] ?? '?',
            $data['ext_version'] ?? 'none', $data['change_seq'] ?? 0,
        ));

        $this->info('running initial full reconcile...');
        $syncEngine->reconcile($server);
        $this->info(sprintf(
            'reconciled: %d clients, %d tunnels, %d hosts',
            $server->clients()->count(), $server->tunnels()->count(), $server->hosts()->count(),
        ));

        SyncVersionJob::dispatch($server->id)->onQueue('nps-sync');
        SyncOnlineJob::dispatch($server->id)->onQueue('nps-sync');
        SyncFlowJob::dispatch($server->id)->onQueue('nps-sync');
        $this->info('sync jobs dispatched (run: php artisan queue:work --queue=nps-sync)');

        return self::SUCCESS;
    }
}
