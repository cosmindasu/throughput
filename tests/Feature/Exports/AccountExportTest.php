<?php

namespace Tests\Feature\Exports;

use App\Models\BulkOperation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US-CRM-03, §13.2 — export sincron sub prag, job în coadă peste el, descărcare
 * restrânsă la autor, refuz peste plafonul absolut în `DEMO_MODE`.
 */
class AccountExportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_a_synchronous_export_contains_exactly_the_filtered_rows(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(4)->create(['created_by' => $this->owner->getKey(), 'status' => 'active']);
            (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey(), 'status' => 'inactive']);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts/export?filter[status]=active');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $lines = array_filter(explode("\n", trim($response->getContent())));
        // Antet + exact 4 rânduri filtrate — nici cele 3 inactive, nici mai puține.
        $this->assertCount(5, $lines);
    }

    /**
     * P1-002 din auditul specificației — completează testul care lipsea pentru calea de
     * export: filtrarea pe un membru anume (id-ul lui) produce exact rândurile lui, nu
     * rândurile colegului sau tot tenantul.
     */
    public function test_a_synchronous_export_filtered_by_a_specific_member_contains_only_their_rows(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, function () use ($agent, $manager): void {
            (new AccountFactory)->create(['name' => 'Agent Account One', 'created_by' => $this->owner->getKey(), 'owner_user_id' => $agent->getKey()]);
            (new AccountFactory)->create(['name' => 'Agent Account Two', 'created_by' => $this->owner->getKey(), 'owner_user_id' => $agent->getKey()]);
            (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $manager->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts/export?filter[owner]='.$agent->getKey());
        $response->assertOk();

        $lines = array_filter(explode("\n", trim($response->getContent())));
        // Antet + exact 2 rânduri ale agentului — nici cele 3 ale managerului.
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Agent Account One', $response->getContent());
        $this->assertStringContainsString('Agent Account Two', $response->getContent());
    }

    public function test_a_viewer_can_export_even_without_write_access(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(2)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get('/marlin/accounts/export')->assertOk();
    }

    public function test_an_export_over_the_threshold_runs_as_a_queued_job_with_the_correct_tenant_context(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_sync_max_rows' => 5]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(8)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts/export');
        $response->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $this->assertSame(BulkOperation::STATUS_PENDING, $operation->status);
        $this->assertSame(8, $operation->total_rows);
        $this->assertSame($this->owner->getKey(), $operation->user_id);

        $this->assertSame(1, DB::table('jobs')->count(), 'Exportul peste prag ar fi trebuit să ajungă în coadă (driverul database).');

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertNotNull($operation->result_path);
        Storage::disk('local')->assertExists($operation->result_path);

        $csv = Storage::disk('local')->get($operation->result_path);
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(9, $lines);
    }

    /**
     * P1-003 — `ExportListJob` NU mai rulează sub `ApplyTenantContextToJob` (o singură
     * tranzacție pentru tot `handle()`), ci în două tranzacții scurte prin
     * `TenantContext::run()` (ADR-014, pct. 5): prima marchează `running` și SE COMITE
     * înainte ca a doua să înceapă munca. Verificarea nu poate opri jobul la jumătate
     * (rulează sincron, în `queue:work --once`), deci prinde momentul exact al primului
     * commit prin `DB::listen()` (scrierea `status = running`) + evenimentul
     * `TransactionCommitted`, și citește starea printr-un `TenantContext::run()` NOU —
     * o tranzacție separată de cea care tocmai a scris `running` — exact ce ar vedea o
     * cerere de polling concurentă cu jobul.
     */
    public function test_the_running_status_commits_in_its_own_transaction_before_the_final_phase(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_sync_max_rows' => 5]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(8)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts/export')->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $this->clearDatabaseTenantContext();

        $sawRunningWrite = false;
        $capturing = false;
        $statusAfterFirstPhase = null;
        $tenantDuringCheck = null;

        DB::listen(function ($query) use (&$sawRunningWrite): void {
            if (! $sawRunningWrite
                && str_contains($query->sql, 'bulk_operations')
                && in_array(BulkOperation::STATUS_RUNNING, $query->bindings, true)) {
                $sawRunningWrite = true;
            }
        });

        Event::listen(TransactionCommitted::class, function () use (&$sawRunningWrite, &$capturing, &$statusAfterFirstPhase, &$tenantDuringCheck, $operation): void {
            // Prima tranzacție care scrie "running" e cea de interes — tranzacția anterioară
            // (ridicarea jobului din tabela `jobs`, driverul `database`) se comite și ea,
            // dar înainte să vedem scrierea de "running". `$capturing` se pune ÎNAINTE de a
            // apela `TenantContext::run()` mai jos: acel apel comite la rândul lui, deci
            // retrage ACELAȘI eveniment — fără gardă, recursivitatea nu s-ar opri niciodată.
            if (! $sawRunningWrite || $capturing) {
                return;
            }

            $capturing = true;

            $statusAfterFirstPhase = TenantContext::run($this->marlin, function () use ($operation, &$tenantDuringCheck) {
                $tenantDuringCheck = TenantScope::currentTenantId();

                return $operation->fresh()->status;
            });
        });

        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $this->assertSame(
            BulkOperation::STATUS_RUNNING,
            $statusAfterFirstPhase,
            'Starea "running" trebuie vizibilă dintr-o tranzacție separată înainte ca faza finală a jobului să scrie starea terminală.'
        );
        $this->assertSame($this->marlin->getKey(), $tenantDuringCheck, 'Tenantul corect trebuie restaurat pentru citirea din tranzacția separată.');

        // Ambele faze restaurează contextul la ieșire (TenantContext::run) — după ce jobul
        // s-a încheiat complet, containerul nu mai ține niciun tenant legat.
        $this->assertNull(TenantScope::currentTenantId(), 'Tenantul trebuie restaurat corect după AMBELE faze ale jobului.');

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertNotNull($operation->result_path);
    }

    public function test_only_the_author_can_download_a_completed_export(): void
    {
        Storage::fake('local');

        $otherOwner = $this->makeMember($this->marlin, 'demo.other-owner@throughput.dev', Permissions::OWNER);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'accounts',
            'action' => 'export',
            'filter_snapshot' => ['filter' => [], 'sort' => 'name', 'userId' => $this->owner->getKey()],
            'total_rows' => 0,
            'status' => BulkOperation::STATUS_COMPLETED,
            'result_path' => 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.csv',
        ]));
        Storage::disk('local')->put($operation->result_path, "Name\n");
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/exports/{$operation->id}/download")->assertOk();

        $this->actingAs($otherOwner)->get("/marlin/exports/{$operation->id}/download")->assertForbidden();
    }

    public function test_an_export_beyond_the_absolute_demo_cap_is_refused(): void
    {
        config([
            'throughput.demo.mode' => true,
            'throughput.limits.export_sync_max_rows' => 2,
            'throughput.limits.bulk_max_rows' => 5,
        ]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(8)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());

        $this->actingAs($this->owner)->get('/marlin/accounts/export')
            ->assertRedirect()
            ->assertSessionHas('error');

        $after = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());
        $this->assertSame($before, $after, 'Refuzul nu trebuie să lase o operație în masă în urmă.');
    }
}
