<?php

namespace Tests\Feature\Bulk;

use App\Jobs\Bulk\ProcessBulkChunkJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Tests\TestCase;

/**
 * §13.2 pct. 3/5, §13.3 (US-BULK-01) — raportul lotului E: bulk-ul scrie UN rând de
 * `activity_log` PER înregistrare atinsă, cu `bulk_operation_id` populat, ca linkul
 * „activity_log filtrat pe această operație" să fie real.
 */
class BulkActivityLogInstrumentationTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $newOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->newOwner = $this->makeMember($this->marlin, 'new-owner@throughput.dev', Permissions::MANAGER);
        $this->clearDatabaseTenantContext();
    }

    public function test_reassigning_owner_in_bulk_writes_one_log_row_per_changed_account(): void
    {
        [$changed, $alreadyThere] = TenantContext::run($this->marlin, function (): array {
            $changed = (new AccountFactory)->count(3)->create([
                'owner_user_id' => $this->owner->getKey(),
                'created_by' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);
            // Deja pe noul owner — ReassignOwnerAction n-o atinge, deci n-are ce diff să existe.
            $alreadyThere = (new AccountFactory)->create([
                'owner_user_id' => $this->newOwner->getKey(),
                'created_by' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);

            return [$changed, $alreadyThere];
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post(
            '/marlin/accounts/bulk/reassign-owner?filter[status]=active',
            ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('reassign_owner');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());

        $this->drainBulkQueue();

        $logs = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()->where('action', 'bulk_action')->where('bulk_operation_id', $operation->getKey())->get(),
        );

        // Exact cele 3 conturi efectiv schimbate — NU 4 (cel deja pe `newOwner` n-a
        // generat niciun rând, deși a fost în selecție/chunk).
        $this->assertCount(3, $logs);
        $this->assertSame($changed->pluck('id')->sort()->values()->all(), $logs->pluck('auditable_id')->sort()->values()->all());

        $logs->each(function (ActivityLog $log) {
            $this->assertSame(Account::class, $log->auditable_type);
            $this->assertSame($this->owner->getKey(), $log->user_id);
            $this->assertSame($this->owner->getKey(), $log->old_values['owner_user_id']);
            $this->assertSame($this->newOwner->getKey(), $log->new_values['owner_user_id']);
            $this->assertIsString($log->ip_address);
            $this->assertIsString($log->user_agent);
        });

        // Contul neschimbat rămâne fără NICIUN rând `bulk_action` pentru această operație.
        $untouchedLog = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()->where('auditable_type', Account::class)->where('auditable_id', $alreadyThere->getKey())->where('action', 'bulk_action')->count(),
        );
        $this->assertSame(0, $untouchedLog);
    }

    /**
     * Idempotență: reluarea manuală a ACELUIAȘI chunk (simulând un redelivery de coadă)
     * nu dublează rândurile de jurnal — garanția vine din `bulk_operation_chunks`
     * (`insertOrIgnore`), verificată aici la nivelul EFECTULUI asupra `activity_log`.
     */
    public function test_retrying_the_same_chunk_does_not_duplicate_log_rows(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'owner_user_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post(
            '/marlin/accounts/bulk/reassign-owner',
            ['selectAllMatching' => false, 'ids' => [$account->getKey()], 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('reassign_owner');
        $response->assertRedirect();
        $this->drainBulkQueue();

        // Rulează din nou EXACT același job de chunk (index 0) — ca un redelivery real.
        ProcessBulkChunkJob::dispatch(
            $this->marlin->getKey(),
            $operation->getKey(),
            'accounts',
            'reassign_owner',
            [$account->getKey()],
            ['owner_user_id' => $this->newOwner->getKey()],
            0,
            $this->owner->getKey(),
            '127.0.0.1',
            'PestTest/1.0',
        )->onQueue('bulk');
        $this->drainBulkQueue();

        $count = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()->where('bulk_operation_id', $operation->getKey())->count(),
        );

        $this->assertSame(1, $count);
    }

    private function soleOperation(string $action): BulkOperation
    {
        return TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', 'accounts')->where('action', $action)->firstOrFail(),
        );
    }

    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
