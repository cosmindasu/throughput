<?php

namespace Tests\Feature\Bulk;

use App\Actions\Bulk\ReassignOwnerAction;
use App\Jobs\Bulk\PlanBulkOperationJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
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

    /**
     * P1-001 (code review) — „Cancel" pe o operație `pending` (planificatorul n-a rulat
     * încă deloc, `batch_id` inexistent) nu făcea NIMIC înainte de fix: `cancel()` acționa
     * doar când exista deja un `batch_id`. Planificatorul pornea apoi normal și rula
     * operația complet, ignorând anularea.
     */
    public function test_cancelling_a_pending_operation_before_the_planner_runs_actually_stops_it(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(BulkOperation::STATUS_PENDING, $operation->status);
        $this->assertNull($operation->batch_id);

        $this->actingAs($this->owner)->post("/marlin/bulk/{$operation->getKey()}/cancel")
            ->assertRedirect()
            ->assertSessionHas('success', 'Cancelled.');

        $cancelled = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_CANCELLED, $cancelled->status);

        // Planificatorul rulează acum din coadă — trebuie să iasă imediat (prima
        // verificare din `PlanBulkOperationJob::handle()`, `status !== pending`), fără să
        // reasigneze nimic și fără să creeze niciun batch/job de chunk.
        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--stop-when-empty' => true, '--no-interaction' => true]);

        $stillCancelled = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_CANCELLED, $stillCancelled->status);
        $this->assertNull($stillCancelled->batch_id);

        $reassignedCount = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $this->assertSame(0, $reassignedCount, 'Nimic nu trebuie reasignat pe o operație anulată înainte ca planificatorul să ruleze.');
    }

    /**
     * P1-001 (code review) — fereastra ÎNGUSTĂ: planificatorul a scris deja „running"
     * (prima tranzacție a lui `PlanBulkOperationJob::handle()`, comisă), dar n-a apucat
     * încă să scrie `batch_id` (a doua tranzacție, încă în curs de construit chunk-urile).
     * O anulare care ajunge EXACT în acel interval nu trebuie ignorată.
     *
     * Reprodusă determinist prin starea, nu prin timing real între procese: se simulează
     * exact ce ar exista în baza de date în acel moment (status `running`, `batch_id`
     * `null`), se anulează prin ruta HTTP normală, apoi se invocă direct — via reflecție,
     * ca planificatorul să nu retrimită operația prin ÎNTREG `handle()`, care ar fi
     * blocat-o deja la verificarea „status === pending" — metoda privată `plan()`, exact
     * punctul din cod unde planificatorul ar fi continuat dacă nu exista verificarea nouă.
     */
    public function test_a_cancellation_that_lands_while_the_planner_is_mid_flight_still_stops_the_batch_from_being_created(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());

        // Simulează exact starea de după prima tranzacție a planificatorului: „running",
        // fără `batch_id` încă.
        TenantContext::run($this->marlin, fn () => $operation->update(['status' => BulkOperation::STATUS_RUNNING]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/bulk/{$operation->getKey()}/cancel")
            ->assertRedirect()
            ->assertSessionHas('success', 'Cancelled.');

        $cancelled = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_CANCELLED, $cancelled->status);
        $this->assertNull($cancelled->batch_id);

        // Planificatorul, dacă ar continua acum din `plan()`, trebuie să vadă starea
        // deja `cancelled` (comisă de cererea de mai sus) și să iasă FĂRĂ să creeze
        // niciun batch/job de chunk.
        $jobsBefore = DB::table('jobs')->count();

        TenantContext::run($this->marlin, function () use ($operation): void {
            $job = new PlanBulkOperationJob($this->marlin->getKey(), $operation->getKey());
            $plan = new ReflectionMethod($job, 'plan');
            $plan->setAccessible(true);
            $plan->invoke($job, $operation->fresh());
        });

        $untouched = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_CANCELLED, $untouched->status, 'A doua verificare din plan() trebuie să respecte anularea deja comisă.');
        $this->assertNull($untouched->batch_id, 'Niciun batch nu trebuie creat peste o operație deja anulată.');
        $this->assertSame($jobsBefore, DB::table('jobs')->count(), 'Niciun ProcessBulkChunkJob nu trebuie pus în coadă.');
        $this->assertSame(0, DB::table('job_batches')->count());
    }

    /**
     * P3 (code review) — `BulkOperationPolicy::cancel()` verifica doar `user_id`: o
     * operație de EXPORT (`action === 'export'`, `App\Support\Exports\ListExport`) deschisă
     * prin `/bulk/{id}` arăta un buton „Cancel" fără niciun efect real (exportul rulează
     * ca un job unic, fără `Bus::batch()`). Acum policy-ul refuză direct.
     */
    public function test_cancel_has_no_effect_on_an_export_operation(): void
    {
        $export = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'accounts',
            'action' => 'export',
            'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
            'total_rows' => 5,
            'status' => BulkOperation::STATUS_PENDING,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/bulk/{$export->getKey()}/cancel")->assertForbidden();
    }

    /**
     * P3 (code review) — și invers: o operație de export deschisă prin ruta de SCRIERE
     * (`bulk.show`) rămâne pe ruta ei (`exports.show`).
     */
    public function test_an_export_operation_cannot_be_opened_through_the_bulk_show_route(): void
    {
        $export = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'accounts',
            'action' => 'export',
            'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
            'total_rows' => 5,
            'status' => BulkOperation::STATUS_COMPLETED,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/bulk/{$export->getKey()}")->assertNotFound();
    }

    /**
     * P3 (code review) — și invers: o operație de SCRIERE deschisă prin ruta de export
     * (`exports.show`) n-are ce descărca (`result_path` nu se scrie niciodată pentru un
     * `action` din `BulkChunkActions`) — rămâne pe ruta ei (`bulk.show`).
     */
    public function test_a_write_operation_cannot_be_opened_through_the_exports_route(): void
    {
        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
            'total_rows' => 5,
            'status' => BulkOperation::STATUS_COMPLETED,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/exports/{$operation->getKey()}")->assertNotFound();
    }
}
