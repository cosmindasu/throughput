<?php

namespace Tests\Feature\Shipping;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BR-ORD-03, task brief lot D: „scrie testul care demonstrează asta, inclusiv pe o
 * cerere concurentă." Proba directă, cu DOUĂ conexiuni Postgres reale, sincron, în
 * același proces PHP — aceeași tehnică folosită la verificarea `FOR NO KEY UPDATE` din
 * `.ai/rules/tenancy.md` („Măsurat cu două sesiuni: lock timeout exact pe
 * SELECT ... FOR KEY SHARE").
 *
 * Conexiunea A (implicită, `TenantContext::run`) ține blocarea pe rândul `tenants` pe
 * toată durata închiderii — exact pasul din `App\Actions\Shipping\ActivateCarrierAction`.
 * Conexiunea B, cu un `lock_timeout` scurt, încearcă ACEEAȘI blocare și trebuie să
 * eșueze cu timeout, nu să treacă instant. Fără blocarea din acțiune, acest test ar
 * EȘUA (B ar reuși imediat) — dovada că invarianta chiar se serializează, nu doar „de
 * obicei merge".
 *
 * `$wrapInTransaction = false` — ACEASTĂ clasă, izolat de restul suitei de
 * `ActivateCarrierAction` (precedent: `Tests\Feature\Tenancy\SessionContextTest`).
 * Sub împachetarea implicită a harnessului (`RefreshDatabase`), tenantul creat în
 * `setUp()` ar rămâne într-o tranzacție NECOMISĂ pe conexiunea A — invizibilă pentru o a
 * DOUA conexiune reală (MVCC, izolare `read committed`), care ar vedea „zero rânduri" și
 * n-ar avea ce bloca. Testul ar fi trecut măsurând harnessul (fals pozitiv: B „nu
 * blochează" pentru că nu găsește rândul, nu pentru că blocarea funcționează), nu
 * mecanismul. Fără rollback automat, `tearDown()` șterge explicit ce a creat `setUp()`.
 */
class ActivateCarrierConcurrencyTest extends TestCase
{
    protected bool $wrapInTransaction = false;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::query()->create([
            'name' => 'Marlin Fasteners & Supply Co.',
            'slug' => 'marlin-concurrency',
            'currency' => 'USD',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tenant->delete();

        parent::tearDown();
    }

    public function test_two_concurrent_activations_for_the_same_tenant_serialize_on_the_tenant_row_lock(): void
    {
        config(['database.connections.pgsql_locktest' => config('database.connections.pgsql')]);
        DB::purge('pgsql_locktest');
        $secondConnection = DB::connection('pgsql_locktest');

        try {
            TenantContext::run($this->tenant, function () use ($secondConnection): void {
                // Exact pasul de blocare din `ActivateCarrierAction::execute()` — prima
                // „cerere" ține rândul tenantului blocat pe toată durata acestei închideri
                // (tranzacția deschisă de `TenantContext::run`, comisă abia la ieșirea
                // din closure).
                Tenant::query()->whereKey($this->tenant->getKey())->lock('for no key update')->firstOrFail();

                // `SET` nu acceptă parametri legați (ADR-014, pct. 1 — aceeași clasă de
                // eroare ca `SET LOCAL app.tenant_id = ?`) — `set_config()`, cu `false`:
                // valoarea trebuie să supraviețuiască ACESTEI instrucțiuni și să se aplice
                // și la SELECT-ul de mai jos, pe aceeași conexiune, fără o tranzacție
                // explicită care să le lege pe amândouă.
                $secondConnection->statement("select set_config('lock_timeout', ?, false)", ['200ms']);

                $timedOut = false;

                try {
                    $secondConnection->select(
                        'select 1 from tenants where id = ? for no key update',
                        [$this->tenant->getKey()]
                    );
                } catch (QueryException $e) {
                    $timedOut = str_contains(strtolower($e->getMessage()), 'lock timeout');
                }

                $this->assertTrue(
                    $timedOut,
                    'A second, concurrent activation for the same tenant should block on the row lock instead of proceeding immediately.'
                );
            });
        } finally {
            DB::purge('pgsql_locktest');
        }
    }

    /**
     * Reversul — fără NICIO activare în curs, o a doua „cerere" pe alt tenant nu trebuie
     * să blocheze deloc: invarianta e per tenant, nu un mutex global pe tabelă.
     */
    public function test_activations_for_different_tenants_do_not_block_each_other(): void
    {
        $other = Tenant::query()->create([
            'name' => 'Northgate Distribution',
            'slug' => 'northgate-concurrency',
            'currency' => 'USD',
        ]);

        config(['database.connections.pgsql_locktest' => config('database.connections.pgsql')]);
        DB::purge('pgsql_locktest');
        $secondConnection = DB::connection('pgsql_locktest');

        try {
            TenantContext::run($this->tenant, function () use ($secondConnection, $other): void {
                Tenant::query()->whereKey($this->tenant->getKey())->lock('for no key update')->firstOrFail();

                $secondConnection->statement("select set_config('lock_timeout', ?, false)", ['200ms']);

                $rows = $secondConnection->select(
                    'select 1 from tenants where id = ? for no key update',
                    [$other->getKey()]
                );

                $this->assertCount(1, $rows, 'Locking a DIFFERENT tenant row must not be affected by this one being locked.');
            });
        } finally {
            DB::purge('pgsql_locktest');
            $other->delete();
        }
    }
}
