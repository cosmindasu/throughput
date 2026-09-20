<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\RegisterPaymentAction;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `RegisterPaymentAction` — US-BILL-02, §12.1. Plată parțială → `balance_due` reflectă
 * exact ce mai e de încasat; a doua plată completă → `status` devine `paid` automat.
 */
class RegisterPaymentActionTest extends TestCase
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

    /** Gherkin US-BILL-02 exact: total 5.000, 2.000 apoi 3.000 → paid automat. */
    public function test_a_partial_then_a_full_payment_marks_the_invoice_paid(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 5000);
            $invoice = $this->sentInvoice($order, 5000);

            $action = new RegisterPaymentAction;

            $first = $action->execute($invoice, ['amount' => 2000, 'method' => Payment::METHOD_BANK_TRANSFER, 'paid_at' => now()], $this->owner);

            $invoice->refresh();
            $this->assertSame(2000.0, (float) $invoice->amount_paid);
            $this->assertSame(3000.0, (float) $invoice->balance_due);
            $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
            $this->assertSame($this->owner->getKey(), $first->created_by);

            $action->execute($invoice, ['amount' => 3000, 'method' => Payment::METHOD_CHECK, 'paid_at' => now()], $this->owner);

            $invoice->refresh();
            $this->assertSame(5000.0, (float) $invoice->amount_paid);
            $this->assertSame(0.0, (float) $invoice->balance_due);
            $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
            $this->assertSame(2, Payment::query()->where('invoice_id', $invoice->getKey())->count());
        });
    }

    public function test_marks_an_overdue_invoice_paid_too(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 1000);
            $invoice = $this->sentInvoice($order, 1000);
            $invoice->update(['status' => Invoice::STATUS_OVERDUE]);

            (new RegisterPaymentAction)->execute($invoice, ['amount' => 1000, 'method' => Payment::METHOD_MANUAL, 'paid_at' => now()], $this->owner);

            $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        });
    }

    public function test_refuses_a_payment_exceeding_the_balance_due(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500);

            try {
                (new RegisterPaymentAction)->execute($invoice, ['amount' => 600, 'method' => Payment::METHOD_MANUAL, 'paid_at' => now()], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }

            $this->assertSame(0, Payment::query()->count());
        });
    }

    public function test_refuses_a_payment_on_a_draft_invoice(): void
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

            try {
                (new RegisterPaymentAction)->execute($invoice, ['amount' => 100, 'method' => Payment::METHOD_MANUAL, 'paid_at' => now()], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_refuses_a_payment_on_a_void_invoice(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 500);
            $invoice = $this->sentInvoice($order, 500);
            $invoice->update(['status' => Invoice::STATUS_VOID, 'void_reason' => 'Mistake.', 'voided_at' => now()]);

            try {
                (new RegisterPaymentAction)->execute($invoice, ['amount' => 100, 'method' => Payment::METHOD_MANUAL, 'paid_at' => now()], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }
}
