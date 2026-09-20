<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\VoidInvoiceAction;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `VoidInvoiceAction` — §12.1, „→ void, din orice stare, cu motiv".
 */
class VoidInvoiceActionTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_voids_a_sent_invoice_with_a_reason_and_keeps_its_payment_history(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500, amountPaid: 200);

            $voided = (new VoidInvoiceAction)->execute($invoice, 'Customer disputes the amount.');

            $this->assertSame(Invoice::STATUS_VOID, $voided->status);
            $this->assertSame('Customer disputes the amount.', $voided->void_reason);
            $this->assertNotNull($voided->voided_at);
            // Trasabilitate (BR-BILL-01) — încasarea deja înregistrată rămâne neatinsă.
            $this->assertSame(200.0, (float) $voided->amount_paid);
        });
    }

    public function test_refuses_to_void_an_already_void_invoice(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500);
            (new VoidInvoiceAction)->execute($invoice, 'First reason.');

            try {
                (new VoidInvoiceAction)->execute($invoice->fresh(), 'Second reason.');
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_voids_a_draft_invoice_too(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 500);
            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'INV-1',
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->toDateString(),
                'currency' => 'USD',
                'subtotal' => 500,
                'tax_total' => 0,
                'total' => 500,
                'amount_paid' => 0,
                'balance_due' => 500,
                'pdf_status' => Invoice::PDF_STATUS_READY,
            ]);
            $invoice->save();

            $voided = (new VoidInvoiceAction)->execute($invoice, 'Created by mistake.');

            $this->assertSame(Invoice::STATUS_VOID, $voided->status);
        });
    }
}
