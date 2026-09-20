<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\JobThatSerializesATenantModel;
use Tests\Fixtures\Jobs\RecordVisibleAccountsJob;
use Tests\TestCase;

/**
 * FR-TEST-03 — joburile din coadă, rulate pe o coadă REALĂ (driverul `database`, vezi
 * comentariul din `phpunit.xml`).
 *
 * Cu `sync` sau `Queue::fake()`, jobul rulează în procesul care l-a dispecerizat, cu
 * contextul cererii încă viu — adică exact condiția în care bug-ul de serializare din §6.3
 * e invizibil. Testele de mai jos există ca să-l facă vizibil.
 */
class QueuedJobContextTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->user = $this->makeMember($this->marlin, 'demo.owner@throughput.dev');

        $this->createAccount($this->marlin, 'Marlin Industrial Fasteners LLC');
        $this->createAccount($this->cascade, 'Cascade Hydraulics Group Inc.');

        // Faza 5, lotul E (ADR-007) — cele două `Account::save()` de mai sus declanșează
        // acum și `App\Observers\ActivityLogObserver`, care pune câte un job
        // `WriteActivityLogEntry` în coadă (aceeași coadă `database` folosită mai jos de
        // `workTheQueue()`). Fără acest drain, cele două joburi de jurnal ar fi primele din
        // coadă — `workTheQueue(1)`/`--once` ar procesa jurnalul, nu jobul pe care testul
        // chiar vrea să-l verifice. Golim coada AICI, înainte ca fiecare test să dispecerizeze
        // propriile joburi, ca `--once`/numărătoarea exactă din acest fișier să rămână corecte.
        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--no-interaction' => true]);
    }

    public function test_a_tenant_job_restores_its_own_context_and_sees_only_its_tenant(): void
    {
        RecordVisibleAccountsJob::dispatch($this->marlin->getKey());
        RecordVisibleAccountsJob::dispatch($this->cascade->getKey());

        $this->assertSame(2, DB::table('jobs')->count(), 'Jobul nu a ajuns în coadă — driverul e tot `sync`?');

        $this->workTheQueue(2);

        $this->assertSame(['Marlin Industrial Fasteners LLC'], Cache::get('visible-accounts:'.$this->marlin->getKey()));
        $this->assertSame(['Cascade Hydraulics Group Inc.'], Cache::get('visible-accounts:'.$this->cascade->getKey()));
    }

    public function test_two_jobs_in_a_row_do_not_inherit_each_others_context(): void
    {
        // Scurgerea clasică: același worker, aceeași conexiune, două joburi consecutive.
        // Cu `set_config(..., false)` (echivalentul lui `SET` simplu), al doilea job ar fi
        // moștenit tenantul primului — reprodus în laborator la verificarea ADR-014.
        RecordVisibleAccountsJob::dispatch($this->marlin->getKey());
        $this->workTheQueue(1);

        RecordVisibleAccountsJob::dispatch($this->cascade->getKey());
        $this->workTheQueue(1);

        $this->assertSame(['Cascade Hydraulics Group Inc.'], Cache::get('visible-accounts:'.$this->cascade->getKey()));
    }

    public function test_after_a_job_finishes_the_worker_process_is_left_without_context(): void
    {
        RecordVisibleAccountsJob::dispatch($this->marlin->getKey());
        $this->workTheQueue(1);

        // Ce contează pentru jobul URMĂTOR: procesul nu rămâne legat de tenantul ăsta.
        $this->assertNull(app()->bound(TenantScope::CONTAINER_KEY)
            ? app(TenantScope::CONTAINER_KEY)
            : null);
    }

    public function test_a_job_that_serializes_a_tenant_scoped_model_fails_instead_of_leaking(): void
    {
        $account = TenantContext::run($this->marlin, fn () => Account::query()->first());

        JobThatSerializesATenantModel::dispatch($account);
        $this->workTheQueue(1);

        // `SerializesModels` re-aduce modelul ÎNAINTE de orice middleware de context, deci
        // fetch-ul rulează fără tenant. Rezultatul corect e un job eșuat zgomotos, nu unul
        // care „merge" citind cine știe ce. De asta regula e o regulă, nu o preferință.
        $failed = DB::table('failed_jobs')->first();

        $this->assertNotNull($failed, 'Jobul cu model serializat ar fi trebuit să eșueze.');
        $this->assertStringContainsString('JobThatSerializesATenantModel', $failed->payload);
    }

    /**
     * Un worker NU are context ambiant: cererea care a dispecerizat jobul s-a încheiat
     * demult. În suită, contextul ar supraviețui totuși — sub tranzacția de test, un commit
     * din cod e doar eliberarea unui savepoint, deci `app.tenant_id` rămâne setat. Fără
     * linia de mai jos, toate testele din acest fișier ar fi trecut verde pentru că jobul
     * moștenea contextul testului, adică exact bug-ul pe care ele pretind că-l exclud.
     */
    private function workTheQueue(int $expected): void
    {
        $this->clearDatabaseTenantContext();

        for ($i = 0; $i < $expected; $i++) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--no-interaction' => true,
            ]);
        }
    }

    private function createAccount(Tenant $tenant, string $name): void
    {
        TenantContext::run($tenant, function () use ($name): void {
            $account = new Account(['name' => $name]);
            $account->created_by = $this->user->getKey();
            $account->save();
        });
    }
}
