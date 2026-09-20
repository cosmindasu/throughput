<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Enums\OrderStatus;
use App\Jobs\Invoices\GenerateInvoicePdfJob;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * `CreateInvoiceAction` — US-BILL-01, §12.1, BR-ORD-02-alike numbering. Verificat direct
 * pe acțiune (fără HTTP), la fel ca `ConfirmOrderActionTest`: RBAC + izolare de tenant au
 * propriul test HTTP (`InvoiceLifecycleHttpTest`), acesta acoperă doar regula de business.
 */
class CreateInvoiceActionTest extends TestCase
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

    public function test_creates_a_draft_invoice_from_a_confirmed_order_and_queues_the_pdf_job(): void
    {
        Queue::fake();

        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 1234.56);

            $invoice = (new CreateInvoiceAction)->execute($order);

            $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
            $this->assertSame($order->getKey(), $invoice->order_id);
            $this->assertNotNull($invoice->invoice_number);
            $this->assertNull($invoice->due_date);
            $this->assertSame(1234.56, (float) $invoice->total);
            $this->assertSame(1234.56, (float) $invoice->balance_due);
            $this->assertSame(0.0, (float) $invoice->amount_paid);
            $this->assertSame(Invoice::PDF_STATUS_PENDING, $invoice->pdf_status);

            Queue::assertPushed(GenerateInvoicePdfJob::class, fn ($job) => $job->invoiceId === $invoice->getKey());
        });
    }

    public function test_refuses_a_draft_order(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 100, status: OrderStatus::Draft);

            try {
                (new CreateInvoiceAction)->execute($order);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            $this->assertSame(0, Invoice::query()->count());
        });
    }

    public function test_allows_a_fulfilled_order(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 200, status: OrderStatus::Fulfilled);

            $invoice = (new CreateInvoiceAction)->execute($order);

            $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        });
    }

    public function test_refuses_a_second_active_invoice_on_the_same_order(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);
            (new CreateInvoiceAction)->execute($order);

            try {
                (new CreateInvoiceAction)->execute($order->fresh());
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('order_id', $e->errors());
            }

            $this->assertSame(1, Invoice::query()->count());
        });
    }

    /**
     * BR-BILL-01 — „→ void și înlocuită cu una nouă, pentru trasabilitate": o factură
     * `void` NU blochează o creare ulterioară pe aceeași comandă.
     */
    public function test_allows_a_replacement_invoice_after_the_previous_one_is_voided(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);
            $first = (new CreateInvoiceAction)->execute($order);
            $first->update(['status' => Invoice::STATUS_VOID, 'void_reason' => 'Wrong amount.', 'voided_at' => now()]);

            $second = (new CreateInvoiceAction)->execute($order->fresh());

            $this->assertNotSame($first->invoice_number, $second->invoice_number);
            $this->assertSame(2, Invoice::query()->count());

            // Review Faza 5 (P2) — testul exercita deja scenariul corect, dar verifica
            // doar `Invoice::query()`, nu relația `Order::invoice()` — exact locul unde
            // codul mort de azi ar fi devenit un bug tăcut la prima folosire
            // (`OrderResource`). `latestOfMany()` trebuie să întoarcă factura ACTIVĂ, nu
            // pe cea `void`, chiar dacă ambele au `created_at` identic (precizie 0).
            $this->assertSame($second->getKey(), $order->fresh()->invoice->getKey());
            $this->assertNotSame($first->getKey(), $order->fresh()->invoice->getKey());
        });
    }

    /**
     * Review Faza 5 (P2) — proba explicită a tiebreaker-ului: `created_at` are precizie 0
     * (`information_schema.columns.datetime_precision`), deci o valoare IDENTICĂ pentru
     * ambele facturi (forțată aici, nu doar sperată din viteza testului) nu trebuie să facă
     * relația nedeterministă — `id` (ULID) decide, corect, în favoarea celei mai recente.
     */
    public function test_order_invoice_relation_breaks_a_created_at_tie_by_id(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);

            $first = (new CreateInvoiceAction)->execute($order);
            $first->update(['status' => Invoice::STATUS_VOID, 'void_reason' => 'Wrong amount.', 'voided_at' => now()]);

            $second = (new CreateInvoiceAction)->execute($order->fresh());

            // `created_at` identic, forțat — proba nu depinde de cât de repede rulează testul.
            $tiedAt = now();
            Invoice::query()->whereIn('id', [$first->getKey(), $second->getKey()])->update(['created_at' => $tiedAt]);

            $this->assertSame($second->getKey(), $order->fresh()->invoice->getKey());
        });
    }

    /**
     * Proba de concurență urmează TIPARUL DEJA ACCEPTAT ÎN PROIECT pentru acest gen de
     * garanție — vezi `ConfirmOrderActionTest::test_order_numbers_are_sequential_and_unique_per_tenant()`
     * și docblock-ul `CreateInvoiceAction::nextInvoiceNumber()`: corectitudine secvențială
     * pe apeluri succesive, NU două conexiuni coordonate manual în jurul unui
     * `lockForUpdate()`.
     */
    public function test_invoice_numbers_are_sequential_and_unique_per_tenant(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $first = (new CreateInvoiceAction)->execute($this->confirmedOrder($this->account, $this->owner, 10));
            $second = (new CreateInvoiceAction)->execute($this->confirmedOrder($this->account, $this->owner, 20));

            $this->assertNotSame($first->invoice_number, $second->invoice_number);
            $this->assertStringEndsWith('-5000', $first->invoice_number);
            $this->assertStringEndsWith('-5001', $second->invoice_number);
            $this->assertSame(
                explode('-', $first->invoice_number)[0],
                explode('-', $second->invoice_number)[0]
            );
        });
    }

    /**
     * Code review P1-001 (imitat de la `ConfirmOrderAction`) — `nextInvoiceNumber()`
     * blochează `tenants` cu `for no key update`, nu `for update`: verificat direct pe
     * SQL-ul emis (Postgres), nu cu două conexiuni coordonate manual.
     */
    public function test_creating_an_invoice_locks_the_tenant_row_with_for_no_key_update(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->confirmedOrder($this->account, $this->owner, 100);

            DB::enableQueryLog();
            (new CreateInvoiceAction)->execute($order);
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            $tenantLockQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for '));

            $this->assertTrue($tenantLockQueries->isNotEmpty(), 'Expected a locking query against tenants.');
            $tenantLockQueries->each(fn (string $sql) => $this->assertStringContainsString(
                'for no key update',
                $sql,
                "Expected `for no key update`, got: {$sql}"
            ));
        });
    }
}
