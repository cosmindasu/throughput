<?php

namespace Tests\Feature\Invoices;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `MarkOverdueInvoicesJob` — proba directă, cu DOUĂ conexiuni Postgres reale, a garanției
 * de concurență argumentate în docblock-ul jobului: `->lock('for no key update')` pe
 * interogarea de selecție serializează două "rulări" ale jobului pe ACEEAȘI factură, iar
 * după ce prima COMITE (starea a trecut la `overdue`), a doua nu mai găsește rândul —
 * mecanismul care previne un al doilea rând de jurnal pentru aceeași tranziție.
 *
 * Aceeași tehnică ca `Tests\Feature\Shipping\ActivateCarrierConcurrencyTest` (vezi acolo
 * comentariul lung despre `.ai/rules/tenancy.md`, „Măsurat cu două sesiuni: lock timeout
 * exact pe SELECT ... FOR KEY SHARE") și ACELAȘI motiv pentru `$wrapInTransaction = false`:
 * sub împachetarea implicită a harnessului (`RefreshDatabase`), fixtura din `setUp()` ar
 * rămâne într-o tranzacție necomisă pe conexiunea A, invizibilă pentru o a doua conexiune
 * reală (MVCC, izolare `read committed`) — testul ar trece măsurând harnessul, nu
 * mecanismul. Fără rollback automat, `tearDown()` șterge explicit ce a creat `setUp()`.
 */
class MarkOverdueInvoicesJobConcurrencyTest extends TestCase
{
    use CreatesInvoices;

    protected bool $wrapInTransaction = false;

    /**
     * Interogarea EXACTĂ din `MarkOverdueInvoicesJob::handle()` (WHERE + `for no key
     * update`), scrisă aici ca SQL brut ca să folosească o a doua conexiune fără Eloquent.
     * Dacă jobul își schimbă vreodată criteriile de eligibilitate fără să actualizeze
     * acest test, proba de blocare de mai jos nu ar mai avea ce demonstra pe rândul greșit
     * — mismatch-ul devine vizibil (rândul nu s-ar mai bloca pe conexiunea B).
     */
    private const LOCK_QUERY = <<<'SQL'
        select id from invoices
        where id = ? and status = 'sent' and due_date is not null and due_date < ? and balance_due > 0
        for no key update
        SQL;

    private Tenant $tenant;

    private User $owner;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin-overdue-concurrency', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        $this->invoice = TenantContext::run($this->tenant, function () {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $order = $this->confirmedOrder($account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        });

        $this->clearDatabaseTenantContext();
    }

    protected function tearDown(): void
    {
        $this->tenant->delete();
        $this->owner->delete();

        // Coada, ULTIMA și OBLIGATORIU — `jobs` e tabelă de framework, fără `tenant_id`, deci
        // ștergerea tenantului de mai sus nu o atinge. Fixtura din `setUp()` și ștergerile de
        // aici salvează modele observate (`Invoice`, `Account`, `Order`), iar de la corecția
        // BR-BILL-02 fiecare salvare pune în coadă un `WriteActivityLogEntry`. Într-un test
        // NEtranzacțional acele rânduri supraviețuiesc, iar următorul test care numără coada
        // le vede ca pe ale lui. Măsurat, nu presupus: fără linia asta, 20 de teste pică în
        // suita completă — `QueueDrainMemoryLimitTest` vede 7 joburi în loc de 2 — toate în
        // directoare care vin alfabetic DUPĂ `Invoices` (Shipments, Tenancy, Webhooks), deci
        // invizibile dacă rulezi doar `tests/Feature/Invoices/`.
        DB::table('jobs')->delete();

        parent::tearDown();
    }

    public function test_a_second_run_blocks_on_the_same_invoice_and_finds_nothing_left_after_the_first_commits(): void
    {
        config(['database.connections.pgsql_locktest' => config('database.connections.pgsql')]);
        DB::purge('pgsql_locktest');
        $secondConnection = DB::connection('pgsql_locktest');

        try {
            TenantContext::run($this->tenant, function () use ($secondConnection): void {
                // Prima "rulare": exact `->lock('for no key update')->get()` din job,
                // ținută deschisă (nicio comitere încă, tranzacția e cea deschisă de
                // `TenantContext::run`) cât timp verificăm conexiunea B.
                $locked = Invoice::query()
                    ->where('status', Invoice::STATUS_SENT)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->where('balance_due', '>', 0)
                    ->lock('for no key update')
                    ->get();
                $this->assertCount(1, $locked, 'Fixtura trebuie să fie eligibilă pentru tranziție înainte de proba de concurență.');

                $secondConnection->statement("select set_config('lock_timeout', ?, false)", ['200ms']);
                $secondConnection->statement("select set_config('app.tenant_id', ?, false)", [$this->tenant->getKey()]);

                $timedOut = false;

                try {
                    $secondConnection->select(self::LOCK_QUERY, [$this->invoice->getKey(), now()->toDateString()]);
                } catch (QueryException $e) {
                    $timedOut = str_contains(strtolower($e->getMessage()), 'lock timeout');
                }

                $this->assertTrue(
                    $timedOut,
                    'A second, concurrent run of the overdue job on the SAME invoice should block on the row lock instead of proceeding immediately.'
                );

                // Efectul real al primei "rulări": exact ce face jobul per factură —
                // `$invoice->update()`, ca să declanșeze observer-ul de audit (nu un
                // UPDATE separat prin query builder, care nu ar dovedi nimic despre
                // evenimentele Eloquent).
                $locked->first()->update(['status' => Invoice::STATUS_OVERDUE]);
            });
            // `TenantContext::run()` a comis tranzacția aici — lock-ul s-a eliberat.

            // A doua "rulare", ACUM: aceeași interogare nu mai găsește rândul (status nu
            // mai e `sent`) — garanția din docblock, verificată direct, nu presupusă.
            $secondConnection->statement("select set_config('lock_timeout', ?, false)", ['1s']);
            $secondConnection->statement("select set_config('app.tenant_id', ?, false)", [$this->tenant->getKey()]);
            $rows = $secondConnection->select(self::LOCK_QUERY, [$this->invoice->getKey(), now()->toDateString()]);

            $this->assertCount(
                0,
                $rows,
                'After the first run commits the transition, a second run must find nothing left to touch — the mechanism that prevents a duplicate activity_log row.'
            );
        } finally {
            DB::purge('pgsql_locktest');
        }
    }
}
