<?php

namespace Tests\Feature\Bulk;

use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkConcurrencyGuard;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Tests\TestCase;

/**
 * §22.5, rândul „Operații în masă (per user): 3 operații concurente active" — ACEEAȘI limită
 * pentru toate rolurile, inclusiv pe exportul Viewer-ului (BR-BULK-03: exportul e o citire
 * permisă, iar contenția vine de aici, nu dintr-un refuz de rol).
 */
class BulkConcurrencyLimitTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->clearDatabaseTenantContext();
    }

    public function test_only_pending_and_running_operations_count_towards_the_limit(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->operation($this->owner, BulkOperation::STATUS_PENDING);
            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_COMPLETED);
            $this->operation($this->owner, BulkOperation::STATUS_FAILED);
            $this->operation($this->owner, BulkOperation::STATUS_CANCELLED);

            $this->assertSame(2, BulkConcurrencyGuard::activeCountFor($this->owner));
            $this->assertFalse(BulkConcurrencyGuard::hasReachedLimit($this->owner));

            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);

            $this->assertSame(3, BulkConcurrencyGuard::activeCountFor($this->owner));
            $this->assertTrue(BulkConcurrencyGuard::hasReachedLimit($this->owner));
        });
    }

    /** Limita e PER UTILIZATOR: operațiile colegului nu blochează pe nimeni. */
    public function test_the_limit_is_counted_per_user(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->operation($this->manager, BulkOperation::STATUS_RUNNING);
            $this->operation($this->manager, BulkOperation::STATUS_RUNNING);
            $this->operation($this->manager, BulkOperation::STATUS_RUNNING);

            $this->assertTrue(BulkConcurrencyGuard::hasReachedLimit($this->manager));
            $this->assertFalse(BulkConcurrencyGuard::hasReachedLimit($this->owner));
        });
    }

    /** O operație de SCRIERE peste plafon: refuz pe cheia `selection`, ca celelalte refuzuri ale aceluiași formular. */
    public function test_a_bulk_write_is_refused_once_three_operations_are_active(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(2)->create([
                'created_by' => $this->owner->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);

            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_PENDING);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->from('/marlin/accounts')
            ->post('/marlin/accounts/bulk/reassign-owner', [
                'selectAllMatching' => true,
                'owner_user_id' => $this->manager->getKey(),
            ])
            ->assertSessionHasErrors(['selection' => BulkConcurrencyGuard::refusal()]);

        // Nicio operație nouă: refuzul e ÎNAINTE de crearea rândului.
        $this->assertSame(3, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    /** …și trece imediat ce una dintre ele se termină. */
    public function test_the_same_bulk_write_goes_through_once_one_operation_finishes(): void
    {
        $finished = null;

        TenantContext::run($this->marlin, function () use (&$finished): void {
            (new AccountFactory)->count(2)->create([
                'created_by' => $this->owner->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);

            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $finished = $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
        });

        TenantContext::run($this->marlin, fn () => $finished->update(['status' => BulkOperation::STATUS_COMPLETED]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post('/marlin/accounts/bulk/reassign-owner', [
                'selectAllMatching' => true,
                'owner_user_id' => $this->manager->getKey(),
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Pagina de progres și „Cancel" NU sunt limitate: un utilizator ajuns la plafon trebuie
     * să poată deschide și opri exact operațiile care-l blochează, altfel limita devine o
     * fundătură până la expirarea joburilor.
     */
    public function test_the_limit_never_blocks_watching_or_cancelling_an_operation(): void
    {
        $operation = null;

        TenantContext::run($this->marlin, function () use (&$operation): void {
            $operation = $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
            $this->operation($this->owner, BulkOperation::STATUS_RUNNING);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/bulk/'.$operation->getKey())->assertOk();
        $this->actingAs($this->owner)->post('/marlin/bulk/'.$operation->getKey().'/cancel')->assertRedirect();
    }

    private function operation(User $user, string $status): BulkOperation
    {
        return BulkOperation::query()->create([
            'user_id' => $user->getKey(),
            'resource_type' => 'accounts',
            'action' => 'reassign_owner',
            'filter_snapshot' => [],
            'total_rows' => 1,
            'status' => $status,
        ]);
    }
}
