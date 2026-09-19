<?php

namespace Tests\Feature\Bulk;

use App\Jobs\System\FailStuckBulkOperationsJob;
use App\Models\BulkOperation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * §13.2 (code review, fix operațional, preexistent din valul 1) — o operație de SCRIERE
 * rămasă `pending`/`running` FĂRĂ `batch_id` mai mult decât pragul e considerată blocată
 * (procesul a murit exact între `Bus::batch()->dispatch()` și scrierea `batch_id`, iar
 * `PlanBulkOperationJob` are `tries = 1` deliberat, deci nu se reîncearcă singur).
 * `FailStuckBulkOperationsJob` o închide ca `failed`, cu un mesaj clar.
 */
class FailStuckBulkOperationsJobTest extends TestCase
{
    private Tenant $marlin;

    private User $marlinOwner;

    private Tenant $cascade;

    private User $cascadeOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->marlinOwner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->cascadeOwner = $this->makeMember($this->cascade, 'demo.cascade-owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_a_write_operation_stuck_without_a_batch_past_the_threshold_is_marked_failed(): void
    {
        $operation = $this->makeOperation($this->marlin, $this->marlinOwner, BulkChunkActions::REASSIGN_OWNER, BulkOperation::STATUS_RUNNING);
        $this->backdate($operation, now()->subMinutes(20));

        (new FailStuckBulkOperationsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->error_message);
        $this->assertNull($fresh->batch_id);
    }

    /** `pending` (jobul planificator încă nu a apucat să scrie „running") e la fel de blocat. */
    public function test_a_pending_write_operation_stuck_past_the_threshold_is_marked_failed(): void
    {
        $operation = $this->makeOperation($this->marlin, $this->marlinOwner, BulkChunkActions::CANCEL_DRAFT_ORDERS, BulkOperation::STATUS_PENDING);
        $this->backdate($operation, now()->subMinutes(20));

        (new FailStuckBulkOperationsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_FAILED, $fresh->status);
    }

    public function test_an_operation_under_the_threshold_is_left_alone(): void
    {
        $operation = $this->makeOperation($this->marlin, $this->marlinOwner, BulkChunkActions::UPDATE_PRICE, BulkOperation::STATUS_RUNNING);
        $this->backdate($operation, now()->subMinutes(5));

        (new FailStuckBulkOperationsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_RUNNING, $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    /**
     * O operație care ȘI-A PRIMIT `batch_id` (planificarea a reușit) nu e „blocată" — chiar
     * dacă rulează de mult (un batch mare, legitim, încă în lucru).
     */
    public function test_an_operation_with_a_batch_id_is_left_alone_no_matter_its_age(): void
    {
        $operation = $this->makeOperation($this->marlin, $this->marlinOwner, BulkChunkActions::SET_ACTIVE, BulkOperation::STATUS_RUNNING);
        TenantContext::run($this->marlin, fn () => $operation->update(['batch_id' => 'fake-batch-id']));
        $this->backdate($operation, now()->subMinutes(20));

        (new FailStuckBulkOperationsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_RUNNING, $fresh->status);
    }

    /**
     * Exporturile (`action = 'export'`) n-au `batch_id` prin design — nu sunt „blocate" în
     * sensul ăsta, un export nu are ce planificator să piardă. `ExportListJob` are deja
     * `$tries = 3` și `failed()` (code review P1, pachetul de export), deci un export ucis
     * abrupt se auto-vindecă prin reîncercarea cozii, fără acest sweeper extern.
     */
    public function test_a_running_export_is_left_alone(): void
    {
        $operation = $this->makeOperation($this->marlin, $this->marlinOwner, 'export', BulkOperation::STATUS_RUNNING);
        $this->backdate($operation, now()->subMinutes(20));

        (new FailStuckBulkOperationsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_RUNNING, $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    public function test_it_runs_correctly_across_multiple_tenants_under_rls_without_leaking(): void
    {
        $marlinStuck = $this->makeOperation($this->marlin, $this->marlinOwner, BulkChunkActions::REASSIGN_OWNER, BulkOperation::STATUS_RUNNING);
        $this->backdate($marlinStuck, now()->subMinutes(20));

        $cascadeStuck = $this->makeOperation($this->cascade, $this->cascadeOwner, BulkChunkActions::REASSIGN_OWNER, BulkOperation::STATUS_RUNNING);
        $this->backdate($cascadeStuck, now()->subMinutes(20));

        $cascadeFresh = $this->makeOperation($this->cascade, $this->cascadeOwner, BulkChunkActions::REASSIGN_OWNER, BulkOperation::STATUS_RUNNING);
        $this->backdate($cascadeFresh, now()->subMinutes(2));

        (new FailStuckBulkOperationsJob)->handle();

        $this->assertSame(BulkOperation::STATUS_FAILED, TenantContext::run($this->marlin, fn () => $marlinStuck->fresh()->status));
        $this->assertSame(BulkOperation::STATUS_FAILED, TenantContext::run($this->cascade, fn () => $cascadeStuck->fresh()->status));
        $this->assertSame(BulkOperation::STATUS_RUNNING, TenantContext::run($this->cascade, fn () => $cascadeFresh->fresh()->status));

        // Contextul nu rămâne legat de ultimul tenant din buclă (ADR-014, `TenantContext::run`
        // restaurează la ieșire) — la fel ca `PruneExpiredExportsJobTest`.
        $this->assertNull(TenantScope::currentTenantId());
    }

    public function test_the_job_is_scheduled_every_five_minutes(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === FailStuckBulkOperationsJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru operațiile blocate lipsește din routes/console.php.');
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    private function makeOperation(Tenant $tenant, User $user, string $action, string $status): BulkOperation
    {
        return TenantContext::run($tenant, fn () => BulkOperation::query()->create([
            'user_id' => $user->getKey(),
            'resource_type' => $action === 'export' ? 'accounts' : 'accounts',
            'action' => $action,
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => $status,
        ]));
    }

    private function backdate(BulkOperation $operation, \DateTimeInterface $when): void
    {
        TenantContext::run(
            $operation->tenant_id,
            fn () => BulkOperation::query()->whereKey($operation->getKey())->update(['updated_at' => $when]),
        );
    }
}
