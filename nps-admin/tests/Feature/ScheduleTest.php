<?php

namespace Tests\Feature;

use App\Jobs\CollectMetricsJob;
use App\Jobs\ReconcileServerJob;
use App\Jobs\SyncFlowJob;
use App\Jobs\SyncOnlineJob;
use App\Jobs\SyncVersionJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P0-1：调度改为节拍派发一次性 Job，且 Job 唯一，避免自循环叠加。
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;
    public function test_schedule_uses_tick_events_without_seed_loops(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        foreach (['nps-sync-version', 'nps-sync-online', 'nps-sync-flow', 'nps-metrics', 'cert-renew'] as $event) {
            $this->assertStringContainsString($event, $output);
        }
        $this->assertStringNotContainsString('nps-sync-seeds', $output);
        $this->assertStringNotContainsString('metrics-seeds', $output);
    }

    public function test_sync_jobs_are_unique_per_server(): void
    {
        $jobs = [
            new SyncVersionJob(1),
            new SyncOnlineJob(1),
            new SyncFlowJob(1),
            new CollectMetricsJob(1),
            new ReconcileServerJob(1),
        ];

        foreach ($jobs as $job) {
            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertSame('1', $job->uniqueId());
        }
    }
}
