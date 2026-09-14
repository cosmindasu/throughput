<?php

namespace Tests\Feature\Bulk;

use App\Jobs\Bulk\ProcessBulkChunkJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contextul de tenant prin ÎNTREG mecanismul — planificator (`PlanBulkOperationJob`), chunk
 * (`ProcessBulkChunkJob`) și finalizare (`FinalizeBulkOperationJob`) — pe coada `database`
 * (`.ai/rules/tenancy.md`: `sync`/`Queue::fake()` ar face bug-ul de serializare invizibil).
 * „Un filtru nu poate atinge rândurile altui tenant, nici prin planificator, nici prin
 * chunk" (task Pachetul C, punctul 8).
 */
class BulkOperationTenancyTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $marlinOwner;

    private User $cascadeOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->marlinOwner = $this->makeMember($this->marlin, 'marlin.owner@throughput.dev', Permissions::OWNER);
        $this->cascadeOwner = $this->makeMember($this->cascade, 'cascade.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    /**
     * Doi tenanți, cu conturi create cu ACELAȘI nume (deliberat — un filtru identic
     * `q=Shared` s-ar potrivi la fel pe amândoi). Operația declanșată pe `marlin` nu
     * trebuie să atingă NICIODATĂ rândurile lui `cascade` — RLS + global scope, verificate
     * prin mecanismul complet (planificator → chunk → finalizare), nu doar prin interogări
     * izolate.
     */
    public function test_a_bulk_operation_dispatched_on_one_tenant_never_touches_another_tenants_rows(): void
    {
        $newOwnerMarlin = $this->makeMember($this->marlin, 'demo.new-owner@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(4)->create(['created_by' => $this->marlinOwner->getKey(), 'name' => 'Shared Industrial Supply Co']));
        TenantContext::run($this->cascade, fn () => (new AccountFactory)->count(4)->create(['created_by' => $this->cascadeOwner->getKey(), 'name' => 'Shared Industrial Supply Co']));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->marlinOwner)->post(
            '/marlin/accounts/bulk/reassign-owner?filter[q]=Shared',
            ['selectAllMatching' => true, 'owner_user_id' => $newOwnerMarlin->getKey()],
        );

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(4, $operation->total_rows, 'Numărul capturat la dispatch trebuie scopat la marlin — 4, nu 8.');

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--stop-when-empty' => true, '--no-interaction' => true]);

        $marlinOwners = TenantContext::run($this->marlin, fn () => Account::query()->pluck('owner_user_id')->unique()->all());
        $this->assertSame([$newOwnerMarlin->getKey()], $marlinOwners);

        // Niciun cont din `cascade` n-a fost atins: owner_user_id rămâne NULL (nesetat la creare).
        $cascadeOwners = TenantContext::run($this->cascade, fn () => Account::query()->pluck('owner_user_id')->unique()->all());
        $this->assertSame([null], $cascadeOwners);

        // Și operația în masă a lui `cascade` nu există deloc — a rulat doar planificatorul lui `marlin`.
        $cascadeOperations = TenantContext::run($this->cascade, fn () => BulkOperation::query()->count());
        $this->assertSame(0, $cascadeOperations);
    }

    /**
     * Job de CHUNK cu `tenantId` scalar explicit (ADR-014) — deserializat și rulat pe coada
     * REALĂ, contextul se restaurează SINGUR în `handle()`, indiferent de ce tenant era activ
     * în procesul care l-a dispecerizat. Mirror-ul lui `QueuedJobContextTest`, pentru
     * mecanismul de bulk specific.
     */
    public function test_a_chunk_job_restores_its_own_tenant_context_regardless_of_the_dispatching_process(): void
    {
        $marlinAccount = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->marlinOwner->getKey()]));
        $cascadeAccount = TenantContext::run($this->cascade, fn () => (new AccountFactory)->create(['created_by' => $this->cascadeOwner->getKey()]));
        $newOwnerMarlin = $this->makeMember($this->marlin, 'marlin.new-owner@throughput.dev', Permissions::MANAGER);
        $newOwnerCascade = $this->makeMember($this->cascade, 'cascade.new-owner@throughput.dev', Permissions::MANAGER);
        $this->clearDatabaseTenantContext();

        $marlinOperation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->marlinOwner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => [],
            'total_rows' => 1,
            'status' => BulkOperation::STATUS_RUNNING,
        ]));
        $cascadeOperation = TenantContext::run($this->cascade, fn () => BulkOperation::query()->create([
            'user_id' => $this->cascadeOwner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => [],
            'total_rows' => 1,
            'status' => BulkOperation::STATUS_RUNNING,
        ]));

        // Dispecerizate direct (fără planificator), ca la `QueuedJobContextTest` — verifică
        // STRICT restaurarea contextului per job, nu tot mecanismul de capturare a filtrului.
        // `.onQueue('bulk')` explicit: în fluxul real, `Bus::batch()->onQueue('bulk')` (din
        // `PlanBulkOperationJob`) o aplică tuturor joburilor din batch — aici, fără batch,
        // trebuie repetată manual, altfel joburile ajung pe coada `default` și
        // `queue:work --queue=bulk` nu găsește nimic de procesat.
        ProcessBulkChunkJob::dispatch($this->marlin->getKey(), $marlinOperation->getKey(), 'accounts', BulkChunkActions::REASSIGN_OWNER, [$marlinAccount->getKey()], ['owner_user_id' => $newOwnerMarlin->getKey()])->onQueue('bulk');
        ProcessBulkChunkJob::dispatch($this->cascade->getKey(), $cascadeOperation->getKey(), 'accounts', BulkChunkActions::REASSIGN_OWNER, [$cascadeAccount->getKey()], ['owner_user_id' => $newOwnerCascade->getKey()])->onQueue('bulk');

        $this->assertSame(2, DB::table('jobs')->count(), 'Joburile n-au ajuns în coadă — driverul e tot `sync`?');

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--stop-when-empty' => true, '--no-interaction' => true]);

        $marlinAccount = TenantContext::run($this->marlin, fn () => Account::query()->find($marlinAccount->getKey()));
        $cascadeAccount = TenantContext::run($this->cascade, fn () => Account::query()->find($cascadeAccount->getKey()));

        $this->assertSame($newOwnerMarlin->getKey(), $marlinAccount->owner_user_id);
        $this->assertSame($newOwnerCascade->getKey(), $cascadeAccount->owner_user_id);

        // Ca la `QueuedJobContextTest::test_after_a_job_finishes_the_worker_process_is_left_without_context()`:
        // verificarea e pe LEGĂTURA din container (`TenantScope::CONTAINER_KEY`), NU pe
        // `current_setting` brut — sub tranzacția de test, un „commit" din cod e doar
        // eliberarea unui SAVEPOINT (Laravel nu emite `RELEASE SAVEPOINT` real la commit),
        // deci `set_config(..., true)` NU se resetează acolo, doar la finalul tranzacției
        // outer a testului. Container-ul, în schimb, chiar e restaurat de
        // `TenantContext::run()` la fiecare ieșire (`preservingContainerBinding()`).
        $this->assertNull(
            app()->bound(TenantScope::CONTAINER_KEY) ? app(TenantScope::CONTAINER_KEY) : null,
            'Procesul workerului nu trebuie să rămână legat de niciun tenant după ultimul job.',
        );
    }
}
