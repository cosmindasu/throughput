<?php

namespace Tests\Feature\Invoices;

use App\Jobs\System\MarkOverdueInvoicesJob;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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

        $fresh = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(Invoice::STATUS_OVERDUE, $fresh->status);
    }

    public function test_leaves_a_sent_invoice_alone_while_its_due_date_is_still_ahead(): void
    {
        $invoice = TenantContext::run($this->marlin, function () {
            $order = $this->confirmedOrder($this->marlinAccount, $this->marlinOwner, 500);

            return $this->sentInvoice($order, 500);
        });
        $this->clearDatabaseTenantContext();

        (new MarkOverdueInvoicesJob)->handle();

        $this->assertSame(Invoice::STATUS_SENT, TenantContext::run($this->marlin, fn () => $invoice->fresh()->status));
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

        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->marlin, fn () => $marlinInvoice->fresh()->status));
        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->cascade, fn () => $cascadeInvoice->fresh()->status));

        // Contextul nu rămâne legat de ultimul tenant din buclă (ADR-014).
        $this->assertNull(TenantScope::currentTenantId());
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
        (new MarkOverdueInvoicesJob)->handle();

        $this->assertSame(Invoice::STATUS_OVERDUE, TenantContext::run($this->marlin, fn () => $invoice->fresh()->status));
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === MarkOverdueInvoicesJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru facturi restante lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit.');
    }
}
