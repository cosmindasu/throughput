<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\MarkInvoiceSentAction;
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
 * `MarkInvoiceSentAction` — deuxième moitié de US-BILL-01: `draft -> sent`, `due_date`
 * din `accounts.credit_terms` (§12.1).
 */
class MarkInvoiceSentActionTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_marking_a_draft_as_sent_computes_the_due_date_from_credit_terms(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Net 60 Co.', 'credit_terms' => 'net_60']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $order = $this->confirmedOrder($account, $this->owner, 500);
            $invoice = new Invoice([
                'order_id' => $order->getKey(),
                'invoice_number' => 'INV-1',
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => '2026-01-01',
                'currency' => 'USD',
                'subtotal' => 500,
                'tax_total' => 0,
                'total' => 500,
                'amount_paid' => 0,
                'balance_due' => 500,
                'pdf_status' => Invoice::PDF_STATUS_READY,
            ]);
            $invoice->save();

            $sent = (new MarkInvoiceSentAction)->execute($invoice);

            $this->assertSame(Invoice::STATUS_SENT, $sent->status);
            $this->assertSame('2026-03-02', $sent->due_date->toDateString());
        });
    }

    public function test_refuses_a_non_draft_invoice(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Net 30 Co.', 'credit_terms' => 'net_30']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $order = $this->confirmedOrder($account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500);

            try {
                (new MarkInvoiceSentAction)->execute($invoice);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }
}
