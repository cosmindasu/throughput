<?php

namespace Tests\Feature\Invoices;

use App\Jobs\Invoices\GenerateInvoicePdfJob;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `GenerateInvoicePdfJob` — ADR-013, §12.1: `pdf_status` `pending -> ready`/`failed`,
 * randat cu DomPDF ales explicit (decizia proprietarului, 2026-09-19). Verificat direct
 * pe `->handle()`/`->failed()`, ca `GenerateShippingLabelJobTest`.
 */
class GenerateInvoicePdfJobTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_renders_the_pdf_and_marks_the_invoice_ready(): void
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 250.75);
            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'INV-1',
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->toDateString(),
                'currency' => 'USD',
                'subtotal' => 250.75,
                'tax_total' => 0,
                'total' => 250.75,
                'amount_paid' => 0,
                'balance_due' => 250.75,
                'pdf_status' => Invoice::PDF_STATUS_PENDING,
            ]);
            $invoice->save();

            return $invoice->getKey();
        });
        $this->clearDatabaseTenantContext();

        (new GenerateInvoicePdfJob($this->tenant->getKey(), $invoiceId))->handle();

        $fresh = TenantContext::run($this->tenant, fn () => Invoice::query()->find($invoiceId));

        $this->assertSame(Invoice::PDF_STATUS_READY, $fresh->pdf_status);
        $this->assertNotNull($fresh->pdf_path);
        Storage::disk('local')->assertExists($fresh->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($fresh->pdf_path));
    }

    public function test_does_nothing_for_an_invoice_that_is_no_longer_pending(): void
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);

            return $this->sentInvoice($order, 100)->getKey();
        });
        $this->clearDatabaseTenantContext();

        (new GenerateInvoicePdfJob($this->tenant->getKey(), $invoiceId))->handle();

        $fresh = TenantContext::run($this->tenant, fn () => Invoice::query()->find($invoiceId));
        $this->assertSame(Invoice::PDF_STATUS_READY, $fresh->pdf_status, 'Ready deja — nicio randare nouă, niciun fișier scris peste.');
        $this->assertNull($fresh->pdf_path);
    }

    /** Plasă de siguranță (`failed()`) — pentru eșecuri care nu trec prin `catch`-ul din `handle()` (OOM, timeout). */
    public function test_failed_marks_a_pending_invoice_as_failed(): void
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);
            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'INV-1',
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->toDateString(),
                'currency' => 'USD',
                'subtotal' => 100,
                'tax_total' => 0,
                'total' => 100,
                'amount_paid' => 0,
                'balance_due' => 100,
                'pdf_status' => Invoice::PDF_STATUS_PENDING,
            ]);
            $invoice->save();

            return $invoice->getKey();
        });
        $this->clearDatabaseTenantContext();

        $job = new GenerateInvoicePdfJob($this->tenant->getKey(), $invoiceId);
        $job->failed(new \RuntimeException('Simulated OOM.'));

        $fresh = TenantContext::run($this->tenant, fn () => Invoice::query()->find($invoiceId));
        $this->assertSame(Invoice::PDF_STATUS_FAILED, $fresh->pdf_status);
    }

    public function test_failed_does_not_downgrade_an_invoice_already_marked_ready(): void
    {
        $invoiceId = TenantContext::run($this->tenant, function (): string {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);

            return $this->sentInvoice($order, 100)->getKey();
        });
        $this->clearDatabaseTenantContext();

        $job = new GenerateInvoicePdfJob($this->tenant->getKey(), $invoiceId);
        $job->failed(new \RuntimeException('Late redelivery after success.'));

        $fresh = TenantContext::run($this->tenant, fn () => Invoice::query()->find($invoiceId));
        $this->assertSame(Invoice::PDF_STATUS_READY, $fresh->pdf_status);
    }
}
