<?php

namespace Tests\Feature;

use App\Jobs\ProvisionApprovalJob;
use App\Models\Approval;
use App\Models\User;
use App\Services\Portal\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L9：审批状态用条件更新，双击/并发通过只允许一次。
 */
class ApprovalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_approve_only_succeeds_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $approval = Approval::create([
            'user_id' => $user->id,
            'type' => Approval::TYPE_CLIENT,
            'payload' => ['server_id' => 1],
            'status' => Approval::STATUS_SUBMITTED,
            'operation_key' => (string) Str::uuid(),
        ]);

        $service = app(ApprovalService::class);
        $service->approve($approval, $user);

        Queue::assertPushed(ProvisionApprovalJob::class, 1);
        $this->assertSame(Approval::STATUS_APPROVED, $approval->fresh()->status);

        try {
            $service->approve($approval, $user);
            $this->fail('second approve should be rejected');
        } catch (\DomainException) {
            // expected
        }

        Queue::assertPushed(ProvisionApprovalJob::class, 1);
    }
}
