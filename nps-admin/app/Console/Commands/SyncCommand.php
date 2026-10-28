<?php

namespace App\Console\Commands;

use App\Models\NpsServer;
use App\Services\Sync\SyncEngine;
use Illuminate\Console\Command;

/**
 * 手动触发同步：单台或全部（默认 version 增量；--reconcile 全量对账）
 */
class SyncCommand extends Command
{
    protected $signature = 'nps:sync
        {server? : 服务器 id 或 name（空=全部 active）}
        {--reconcile : 全量对账（L3）而非增量（L2）}';

    protected $description = '手动触发 nps 同步（增量或全量对账）';

    public function handle(SyncEngine $syncEngine): int
    {
        $query = NpsServer::where('status', 'active');
        if ($id = $this->argument('server')) {
            $query->where(fn ($q) => $q->where('id', $id)->orWhere('name', $id));
        }

        $servers = $query->get();
        if ($servers->isEmpty()) {
            $this->error('no matching active server');

            return self::FAILURE;
        }

        foreach ($servers as $server) {
            $this->info("[{$server->name}] syncing...");
            try {
                if ($this->option('reconcile')) {
                    $syncEngine->reconcile($server);
                    $this->info(sprintf(
                        '  reconciled: %d clients, %d tunnels, %d hosts',
                        $server->clients()->count(), $server->tunnels()->count(), $server->hosts()->count(),
                    ));
                } else {
                    $result = $syncEngine->syncVersion($server);
                    $this->info("  {$result}");
                }
            } catch (\Throwable $e) {
                $this->error('  failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
