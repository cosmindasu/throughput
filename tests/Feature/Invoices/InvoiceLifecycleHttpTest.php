<?php

namespace Tests\Feature\Invoices;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `InvoiceController`/`PaymentController` prin lanțul real de middleware — DoD: teste
 * HTTP; `can` verificat pe cele 4 roluri (inclusiv refuzurile); izolare de tenant;
 * numerotare, plată parțială → `balance_due`, void, tranzițiile `pdf_status`. La fel ca
 * `OrderTransitionsHttpTest`.
 */
class InvoiceLifecycleHttpTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $marlin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'setup.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_owner_and_manager_can_create_an_invoice_agent_and_viewer_cannot(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner1@throughput.dev', Permissions::OWNER);
        $manager = $this->makeMember($this->marlin, 'manager1@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'agent1@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'viewer1@throughput.dev', Permissions::VIEWER);

        $orderForAgent = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $agent, 100));
        $orderForOwner = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 200));
        $orderForManager = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $manager, 300));
        $orderForViewerAttempt = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 400));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->post("/marlin/orders/{$orderForAgent->getKey()}/invoices")->assertForbidden();
        $this->actingAs($viewer)->post("/marlin/orders/{$orderForViewerAttempt->getKey()}/invoices")->assertForbidden();

        $this->actingAs($owner)
            ->post("/marlin/orders/{$orderForOwner->getKey()}/invoices")
            ->assertRedirect();

        $this->actingAs($manager)
            ->post("/marlin/orders/{$orderForManager->getKey()}/invoices")
            ->assertRedirect();

        $this->assertSame(2, TenantContext::run($this->marlin, fn () => Invoice::query()->count()));
    }

    public function test_creating_an_invoice_from_a_draft_order_is_a_validation_error_not_a_403(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 100, status: OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->post("/marlin/orders/{$order->getKey()}/invoices")
            ->assertSessionHasErrors('status');
    }

    public function test_full_lifecycle_create_send_partial_payment_full_payment(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner3@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 5000));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post("/marlin/orders/{$order->getKey()}/invoices")->assertRedirect();

        $invoice = TenantContext::run($this->marlin, fn () => Invoice::query()->where('order_id', $order->getKey())->firstOrFail());
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);

        $this->actingAs($owner)
            ->patch("/marlin/invoices/{$invoice->getKey()}/send")
            ->assertRedirect("/marlin/invoices/{$invoice->getKey()}");

        $sent = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(Invoice::STATUS_SENT, $sent->status);
        $this->assertNotNull($sent->due_date);

        $this->actingAs($owner)
            ->post("/marlin/invoices/{$invoice->getKey()}/payments", [
                'amount' => 2000,
                'method' => 'bank_transfer',
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect("/marlin/invoices/{$invoice->getKey()}");

        $afterPartial = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(3000.0, (float) $afterPartial->balance_due);
        $this->assertSame(Invoice::STATUS_SENT, $afterPartial->status);

        $this->actingAs($owner)
            ->post("/marlin/invoices/{$invoice->getKey()}/payments", [
                'amount' => 3000,
                'method' => 'check',
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect("/marlin/invoices/{$invoice->getKey()}");

        $afterFull = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(0.0, (float) $afterFull->balance_due);
        $this->assertSame(Invoice::STATUS_PAID, $afterFull->status);
    }

    public function test_agent_cannot_register_a_payment_even_on_their_own_orders_invoice(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner4@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent4@throughput.dev', Permissions::AGENT);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $agent, 500));
        $invoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($order, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->post("/marlin/invoices/{$invoice->getKey()}/payments", [
                'amount' => 100,
                'method' => 'manual',
                'paid_at' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_a_payment_over_the_balance_due_is_a_validation_error(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner5@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $invoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($order, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->post("/marlin/invoices/{$invoice->getKey()}/payments", [
                'amount' => 600,
                'method' => 'manual',
                'paid_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_void_requires_a_reason_and_owner_manager_can_void_agent_and_viewer_cannot(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner6@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent6@throughput.dev', Permissions::AGENT);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $invoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($order, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/invoices/{$invoice->getKey()}/void", ['reason' => 'Duplicate.'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch("/marlin/invoices/{$invoice->getKey()}/void", [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($owner)
            ->patch("/marlin/invoices/{$invoice->getKey()}/void", ['reason' => 'Duplicate invoice.'])
            ->assertRedirect("/marlin/invoices/{$invoice->getKey()}");

        $voided = TenantContext::run($this->marlin, fn () => $invoice->fresh());
        $this->assertSame(Invoice::STATUS_VOID, $voided->status);
        $this->assertSame('Duplicate invoice.', $voided->void_reason);
    }

    /** ADR-013 — „pe failed, motivul + un buton de reîncercare": `pdf_status` `failed -> pending`, redispecerizat. */
    public function test_retrying_a_failed_pdf_resets_it_to_pending_and_requeues_the_job(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner10@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent10@throughput.dev', Permissions::AGENT);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $invoice = TenantContext::run($this->marlin, function () use ($order) {
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['pdf_status' => Invoice::PDF_STATUS_FAILED]);

            return $invoice;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->patch("/marlin/invoices/{$invoice->getKey()}/pdf/retry")->assertForbidden();

        $this->actingAs($owner)
            ->patch("/marlin/invoices/{$invoice->getKey()}/pdf/retry")
            ->assertRedirect("/marlin/invoices/{$invoice->getKey()}");

        $this->assertSame(Invoice::PDF_STATUS_PENDING, TenantContext::run($this->marlin, fn () => $invoice->fresh()->pdf_status));

        $this->workTheQueue();

        $this->assertSame(Invoice::PDF_STATUS_READY, TenantContext::run($this->marlin, fn () => $invoice->fresh()->pdf_status));
    }

    /** §7.4 rândul „Facturi", R* la Agent — Agentul vede propriile comenzi, nu ale altcuiva. */
    public function test_agent_can_view_their_own_invoice_but_not_a_colleagues(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner7@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent7@throughput.dev', Permissions::AGENT);
        $colleague = $this->makeMember($this->marlin, 'colleague7@throughput.dev', Permissions::AGENT);

        $ownOrder = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $agent, 500));
        $ownInvoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($ownOrder, 500));

        $colleagueOrder = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $colleague, 500));
        $colleagueInvoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($colleagueOrder, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get("/marlin/invoices/{$ownInvoice->getKey()}")->assertOk();
        $this->actingAs($agent)->get("/marlin/invoices/{$colleagueInvoice->getKey()}")->assertForbidden();
    }

    public function test_an_invoice_from_another_tenant_is_not_found(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $stranger = $this->makeMember($cascade, 'stranger@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'owner8@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $invoice = TenantContext::run($this->marlin, fn () => $this->sentInvoice($order, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($stranger)->get("/cascade/invoices/{$invoice->getKey()}")->assertNotFound();
    }

    /**
     * ADR-013 — jobul de PDF chiar rulează pe coada REALĂ (`database`, phpunit.xml) și
     * scrie `pdf_status = ready`, la fel ca `GenerateShippingLabelJobTest::workTheQueue()`.
     */
    public function test_creating_an_invoice_queues_the_pdf_job_which_marks_it_ready(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner9@throughput.dev', Permissions::OWNER);
        $order = TenantContext::run($this->marlin, fn () => $this->confirmedOrder($this->account, $owner, 500));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post("/marlin/orders/{$order->getKey()}/invoices")->assertRedirect();

        $invoice = TenantContext::run($this->marlin, fn () => Invoice::query()->where('order_id', $order->getKey())->firstOrFail());
        $this->assertSame(Invoice::PDF_STATUS_PENDING, $invoice->pdf_status);

        $this->workTheQueue();

        $this->assertSame(Invoice::PDF_STATUS_READY, TenantContext::run($this->marlin, fn () => $invoice->fresh()->pdf_status));
    }

    /**
     * Vezi `GenerateShippingLabelJobTest::workTheQueue()` — același motiv, aceeași formă,
     * dar DRENEAZĂ coada până se golește, nu un număr fix de iterații: de când lotul E
     * (jurnalul de activitate, ADR-007) a instrumentat live modelele de business, ORICE
     * salvare de `Order`/`Invoice` mai pune în coadă și `WriteActivityLogEntry` (queued
     * listener), pe lângă jobul testat aici — un număr fix ar deveni fragil la fiecare
     * schimbare a câte joburi auxiliare pornește o acțiune.
     */
    private function workTheQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $guard = 0;

        while (DB::table('jobs')->count() > 0 && $guard < 10) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--no-interaction' => true,
            ]);
            $guard++;
        }

        $failed = DB::table('failed_jobs')->count();
        $this->assertSame(0, $failed, 'A queued invoice PDF job failed unexpectedly — see failed_jobs.');
    }
}
