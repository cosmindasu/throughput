<?php

namespace Tests\Feature\Invoices;

use App\Jobs\System\MarkOverdueInvoicesJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `MarkOverdueInvoicesJob` — BR-BILL-02, „job zilnic marchează sent -> overdue orice
 * factură cu due_date trecut și balance_due > 0". Job de SISTEM (`.ai/rules/tenancy.md`):
 * verificat direct pe `->handle()`, ca `PruneExpiredExportsJobTest`, inclusiv izolarea
 * multi-tenant sub RLS și programarea din `routes/console.php`.
 */
class MarkOverdueInvoicesJobTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $marlin;

    private User $marlinOwner;

    private Account $marlinAccount;

    private Tenant $cascade;

    private User $cascadeOwner;

    private Account $cascadeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->marlinOwner = $this->makeMember($this->marlin, 'marlin.owner@throughput.dev', Permissions::OWNER);
        $this->marlinAccount = TenantContext::run($this->marlin, function () {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $this->marlinOwner->getKey();
            $account->save();

            return $account;
        });

        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->cascadeOwner = $this->makeMember($this->cascade, 'cascade.owner@throughput.dev', Permissions::OWNER);
        $this->cascadeAccount = TenantContext::run($this->cascade, function () {
            $account = new Account(['name' => 'Rivermont Fluid Power', 'credit_terms' => 'net_30']);
            $account->created_by = $this->cascadeOwner->getKey();
            $account->save();

            return $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_marks_a_sent_invoice_overdue_when_its_due_date_has_passed_with_a_balance(): void
    {
        $invoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();
        $this->drainDefaultQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(Invoice::STATUS_OVERDUE, $fresh->status);

        // BR-BILL-02 — „tranziția e vizibilă în activity_log cu user_id = null (acțiune
        // de sistem)". Regresie directă pentru bug-ul găsit: un UPDATE în masă prin query
        // builder nu declanșa evenimentele Eloquent, deci acest rând nu se scria niciodată.
        //
        // Filtrat pe `new_values->status`, NU doar pe `action = 'updated'`: fixtura de mai
        // sus face ȘI un `$invoice->update(['due_date' => ...])` înainte de job (ca să
        // devină eligibilă), ceea ce scrie propriul rând de jurnal, cu `due_date` în diff
        // și fără cheia `status` (observer-ul înregistrează DOAR câmpurile schimbate,
        // §17.1) — un `sole()` nefiltrat pe `status` ar găsi 2 rânduri și ar arunca
        // `MultipleRecordsFoundException`, greșit acuzând jobul de duplicare.
        $log = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->where('auditable_id', $invoice->getKey())
                ->where('action', 'updated')
                ->where('new_values->status', Invoice::STATUS_OVERDUE)
                ->sole(),
        );

        $this->assertNull($log->user_id, 'Tranziția automată trebuie atribuită sistemului, nu unui utilizator.');
        $this->assertSame(Invoice::STATUS_SENT, $log->old_values['status']);
        $this->assertSame(Invoice::STATUS_OVERDUE, $log->new_values['status']);
    }

    public function test_leaves_a_sent_invoice_alone_while_its_due_date_is_still_ahead(): void
    {
        $invoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);

            return $this->sentInvoice($order, 500);
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();
        $this->drainDefaultQueue();

        $this->assertSame(Invoice::STATUS_SENT, TenantContext::run($this->marlin, fn () => $invoice->fresh()->status));

        // O factură NEATINSĂ de job nu capătă niciun rând de jurnal PENTRU O TRANZIȚIE —
        // filtrat pe `action = 'updated'`, nu pe total: crearea facturii (`sentInvoice`)
        // scrie deja, legitim, propriul rând `created`, neatins de bug-ul verificat aici.
        $count = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->where('auditable_id', $invoice->getKey())
                ->where('action', 'updated')
                ->count(),
        );
        $this->assertSame(0, $count, 'O factură neatinsă de job nu trebuie să apară cu niciun rând `updated` în activity_log.');
    }

    public function test_leaves_a_fully_paid_invoice_alone_even_past_its_due_date(): void
    {
        $invoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $invoice = $this->sentInvoice($order, 500, amountPaid: 500);
            $invoice->update(['status' => Invoice::STATUS_PAID, 'due_date' => now()->subWeek()->toDateString()]);

            return $invoice;
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();

        $this->assertSame(Invoice::STATUS_PAID, TenantContext::run($this->marlin, fn () => $invoice->fresh()->status));
    }

    public function test_leaves_a_draft_or_void_invoice_alone(): void
    {
        [$draft, $void] = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $draft = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'INV-DRAFT',
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->subMonth()->toDateString(),
                'due_date' => now()->subWeek()->toDateString(),
                'currency' => 'USD',
                'subtotal' => 100,
                'tax_total' => 0,
                'total' => 100,
                'amount_paid' => 0,
                'balance_due' => 100,
                'pdf_status' => Invoice::PDF_STATUS_READY,
            ]);
            $draft->save();

            $order2 = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 100);
            $void = $this->sentInvoice($order2, 100);
            $void->update(['status' => Invoice::STATUS_VOID, 'void_reason' => 'x', 'voided_at' => now(), 'due_date' => now()->subWeek()->toDateString()]);

            return [$draft, $void];
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();

        $this->assertSame(Invoice::STATUS_DRAFT, TenantContext::run($this->marlin, fn () => $draft->fresh()->status));
        $this->assertSame(Invoice::STATUS_VOID, TenantContext::run($this->marlin, fn () => $void->fresh()->status));
    }

    public function test_it_runs_correctly_across_multiple_tenants_under_rls(): void
    {
        $marlinInvoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 300);
            $invoice = $this->sentInvoice($order, 300);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        });

        $cascadeInvoice = TenantContext::run($this->cascade, function () {
            $order = $this->confirmedOrder($this->cascadeAccount, $this->cascadeOwner, 700);
            $invoice = $this->sentInvoice($order, 700);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();
        $this->drainDefaultQueue();

        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->marlin, fn () => $marlinInvoice->fresh()->status));
        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->cascade, fn () => $cascadeInvoice->fresh()->status));

        // Contextul nu rămâne legat de ultimul tenant din buclă (ADR-014).
        $this->assertNull(TenantScope::currentTenantId());

        // Fiecare rând de jurnal aparține tenantului lui, sub RLS — nu doar starea
        // facturii, ci și scrierea în activity_log respectă izolarea de tenant. Filtrat pe
        // `new_values->status` — vezi nota din primul test: fixtura mai face ȘI un
        // `update(['due_date' => ...])` înainte de job, care scrie propriul rând.
        $marlinLog = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->where('auditable_id', $marlinInvoice->getKey())
                ->where('action', 'updated')
                ->where('new_values->status', Invoice::STATUS_OVERDUE)
                ->sole(),
        );
        $cascadeLog = TenantContext::run(
            $this->cascade,
            fn () => ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->where('auditable_id', $cascadeInvoice->getKey())
                ->where('action', 'updated')
                ->where('new_values->status', Invoice::STATUS_OVERDUE)
                ->sole(),
        );
        $this->assertNull($marlinLog->user_id);
        $this->assertNull($cascadeLog->user_id);

        // Sub RLS, tenantul cascade nu vede rândul de jurnal al lui marlin, și invers.
        $this->assertSame(
            0,
            TenantContext::run(
                $this->cascade,
                fn () => ActivityLog::query()->where('auditable_id', $marlinInvoice->getKey())->count(),
            ),
        );
        $this->assertSame(
            0,
            TenantContext::run(
                $this->marlin,
                fn () => ActivityLog::query()->where('auditable_id', $cascadeInvoice->getKey())->count(),
            ),
        );
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        $invoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();
        $this->drainDefaultQueue();
        (new MarkOverdueInvoicesJob)->handle();
        $this->drainDefaultQueue();

        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->marlin, fn () => $invoice->fresh()->status));

        // A doua rulare nu mai găsește factura în starea `sent` (deja trecută la
        // `overdue` de prima), deci nu o salvează a doua oară — un singur rând de jurnal
        // AL TRANZIȚIEI, nu doi. Filtrat pe `new_values->status`: fixtura mai scrie și
        // rândul propriu al lui `update(['due_date' => ...])`, neatins de idempotență.
        $count = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->where('auditable_id', $invoice->getKey())
                ->where('action', 'updated')
                ->where('new_values->status', Invoice::STATUS_OVERDUE)
                ->count(),
        );
        $this->assertSame(1, $count, 'O a doua rulare a jobului nu trebuie să dubleze rândul de jurnal al aceleiași tranziții.');
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === MarkOverdueInvoicesJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru facturi restante lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit.');
    }

    /**
     * Garda pe tranșe. Jobul iterează cu `chunkById()`, al cărui cursor avansează pe
     * `id > ultimul_id`, deci rândurile deja trecute pe `overdue` rămân ÎN URMA lui și nu
     * contează că ies din `WHERE status = 'sent'`. Cu `chunk()` și OFFSET, a doua tranșă ar
     * sări exact atâtea rânduri câte a modificat prima — o factură rămasă tăcut `sent`,
     * într-o listă de mii, fără nicio eroare nicăieri.
     *
     * Fără acest test, regresia ar fi invizibilă: toate celelalte teste din fișier au sub
     * 500 de facturi, deci nu trec niciodată printr-o a doua tranșă. De asta pragul e în
     * configurare și nu constantă — coborât la 2, aceeași dovadă costă trei comenzi în loc
     * de 501.
     */
    public function test_it_does_not_skip_rows_when_the_eligible_set_spans_several_chunks(): void
    {
        config(['throughput.limits.overdue_chunk_size' => 2]);

        $invoices = TenantContext::run($this->marlin, fn () => collect(range(1, 3))->map(function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        }));
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();

        TenantContext::run($this->marlin, function () use ($invoices): void {
            foreach ($invoices as $index => $invoice) {
                $this->assertSame(
                    Invoice::STATUS_OVERDUE,
                    $invoice->fresh()->status,
                    "Factura #{$index} a rămas needitată — tranșa a doua a sărit rânduri.",
                );
            }
        });
    }

    /**
     * DOM-02 (audit 2026-09-23) — dovada directă că fiecare tranșă își deschide PROPRIA
     * tranzacție, nu că doar interogarea de selecție diferă de la o tranșă la alta.
     * `TenantContext::setTenant()` scrie `select set_config('app.tenant_id', ?, true)` o
     * SINGURĂ dată per apel al lui `TenantContext::run()` (`TenantContext.php`) — deci
     * numărul de asemenea instrucțiuni, pentru bindingul acestui tenant, e exact numărul de
     * ori în care `run()` a fost chemat pentru el.
     *
     * 5 facturi eligibile, tranșă de 2: [2, 2, 1] — a treia tranșă întoarce 1 (< 2) și
     * oprește bucla, deci exact 3 apeluri ale lui `run()`. PE CODUL VECHI (un singur
     * `TenantContext::run()` înfășurând întreaga buclă de tranșe, fie `chunkById()`, fie un
     * `do…while` reîmpachetat greșit), acest numărător ar rămâne 1 indiferent de câte tranșe
     * interne se parcurg — testul pică direct pe regresia pe care vrea s-o prindă, nu doar
     * „probabil".
     */
    public function test_it_opens_a_separate_transaction_per_chunk_instead_of_one_for_the_whole_tenant(): void
    {
        config(['throughput.limits.overdue_chunk_size' => 2]);

        $invoices = TenantContext::run($this->marlin, fn () => collect(range(1, 5))->map(function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['due_date' => now()->subDay()->toDateString()]);

            return $invoice;
        }));
        $this->clearDatabaseTenantContext();

        $tenantContextEntries = 0;
        DB::listen(function (QueryExecuted $query) use (&$tenantContextEntries): void {
            if (
                str_contains($query->sql, "set_config('app.tenant_id'")
                && ($query->bindings[0] ?? null) === $this->marlin->getKey()
            ) {
                $tenantContextEntries++;
            }
        });

        (new MarkOverdueInvoicesJob)->handle();

        $this->assertSame(
            3,
            $tenantContextEntries,
            'Fiecare tranșă trebuie procesată în propriul apel TenantContext::run() (deci în propria tranzacție), nu toate într-unul singur pentru tot tenantul.',
        );

        TenantContext::run($this->marlin, function () use ($invoices): void {
            foreach ($invoices as $index => $invoice) {
                $this->assertSame(
                    Invoice::STATUS_OVERDUE,
                    $invoice->fresh()->status,
                    "Factura #{$index} a rămas needitată.",
                );
            }
        });
    }

    /**
     * ADR-007 — scrierea în `activity_log` trece prin listener-ul PE COADĂ
     * `WriteActivityLogEntry` (`App\Observers\ActivityLogObserver` doar dispecerizează
     * evenimentul, sincron). Suita rulează pe coada `database` (`.ai/rules/tenancy.md`,
     * „Testele rulează cu coadă database, nu sync"), deci rândul nu există în tabelă
     * până nu se drenează coada — la fel ca `ActivityLogObserverTest::drainDefaultQueue()`.
     */
    private function drainDefaultQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
