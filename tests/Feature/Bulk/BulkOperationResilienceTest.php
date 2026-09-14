<?php

namespace Tests\Feature\Bulk;

use App\Actions\Bulk\ReassignOwnerAction;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Tests\TestCase;

/**
 * §13.2, pct. 5/7 — cele două moduri de eșec pe care mecanismul trebuie să le țină: un
 * chunk reîncercat (idempotență) și o anulare la jumătatea unei operații pe mai multe
 * chunk-uri (stare coerentă, 0 rânduri corupte).
 */
class BulkOperationResilienceTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $newOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->newOwner = $this->makeMember($this->marlin, 'demo.new-owner@throughput.dev', Permissions::MANAGER);

        $this->clearDatabaseTenantContext();
    }

    /**
     * `ReassignOwnerAction::apply()` scrie un `UPDATE ... WHERE owner_user_id != :nou (SAU
     * NULL)`, nu un increment necondiționat (§13.2, pct. 5) — a doua aplicare a ACELUIAȘI
     * chunk (reîncercare după un worker mort, timeout, etc.) nu mai atinge niciun rând.
     */
    public function test_reassigning_the_same_chunk_twice_is_a_no_op_the_second_time(): void
    {
        $accountIds = TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(3)
            ->create(['created_by' => $this->owner->getKey()])
            ->pluck('id')
            ->all());
        $this->clearDatabaseTenantContext();

        [$firstRun, $secondRun] = TenantContext::run($this->marlin, function () use ($accountIds): array {
            $resource = BulkWritableResources::resolve('accounts');
            $action = new ReassignOwnerAction;
            $payload = ['owner_user_id' => $this->newOwner->getKey()];

            $first = $action->apply($resource, $accountIds, $payload);
            $second = $action->apply($resource, $accountIds, $payload);

            return [$first, $second];
        });

        $this->assertSame(3, $firstRun, 'Prima aplicare trebuie să schimbe toate cele 3 rânduri.');
        $this->assertSame(0, $secondRun, 'A doua aplicare a ACELUIAȘI chunk nu trebuie să schimbe nimic.');

        $owners = TenantContext::run($this->marlin, fn () => Account::query()->whereIn('id', $accountIds)->pluck('owner_user_id')->unique()->all());
        $this->assertSame([$this->newOwner->getKey()], $owners);
    }

    /**
     * Operație pe 3 chunk-uri (2 rânduri fiecare, `bulk_chunk_size` coborât pentru viteza
     * testului). Se procesează planificatorul + EXACT primul chunk, apoi se anulează —
     * exact „Cancel" din UI. Cele două chunk-uri neîncepute se opresc cooperativ
     * (`$this->batch()->cancelled()`), jobul de finalizare scrie starea terminală.
     * Rezultat așteptat: primele 2 rânduri schimbate, celelalte 4 NEATINSE — 0 rânduri
     * într-o stare intermediară/coruptă.
     */
    public function test_cancelling_midway_leaves_a_coherent_state_with_zero_corrupted_rows(): void
    {
        config(['throughput.limits.bulk_chunk_size' => 2]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(6)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(6, $operation->total_rows);

        // 1) Planificatorul: creează batch-ul + 3 joburi de chunk, nu le procesează încă.
        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertNotNull($operation->batch_id, 'Planificatorul trebuie să fi creat batch-ul.');

        // 2) EXACT primul chunk (2 rânduri).
        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $reassignedSoFar = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $this->assertSame(2, $reassignedSoFar, 'Exact primul chunk trebuie procesat până la anulare.');

        // 3) „Cancel" — cererea autorului, ca din `Bulk/Show.tsx`.
        $this->actingAs($this->owner)->post("/marlin/bulk/{$operation->getKey()}/cancel")->assertRedirect();

        // 4) Drenează restul: cele 2 chunk-uri rămase se opresc cooperativ, jobul de
        // finalizare scrie starea terminală.
        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--stop-when-empty' => true, '--no-interaction' => true]);

        $reassignedTotal = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $untouchedTotal = TenantContext::run($this->marlin, fn () => Account::query()->whereNull('owner_user_id')->count());

        // 0 rânduri corupte: fiecare cont e FIE pe noul owner (primul chunk), FIE neatins
        // (owner_user_id încă NULL) — niciodată o a treia valoare.
        $this->assertSame(2, $reassignedTotal, 'Anularea nu trebuie să lase mai mult de un chunk procesat.');
        $this->assertSame(4, $untouchedTotal, 'Cele 4 rânduri din chunk-urile neîncepute rămân exact cum erau.');
        $this->assertSame(6, $reassignedTotal + $untouchedTotal, 'Niciun rând într-o stare a treia, intermediară.');

        $operation = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_CANCELLED, $operation->status);
    }

    /**
     * Doar autorul poate anula (`BulkOperationPolicy::cancel()`) — un alt Owner din același
     * tenant nu poate opri operația altcuiva.
     */
    public function test_only_the_author_can_cancel_an_operation(): void
    {
        $otherOwner = $this->makeMember($this->marlin, 'demo.other-owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(2)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->firstOrFail());

        $this->actingAs($otherOwner)->post("/marlin/bulk/{$operation->getKey()}/cancel")->assertForbidden();
    }
}
