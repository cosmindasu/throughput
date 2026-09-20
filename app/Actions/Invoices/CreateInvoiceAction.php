<?php

namespace App\Actions\Invoices;

use App\Enums\OrderStatus;
use App\Jobs\Invoices\GenerateInvoicePdfJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-BILL-01 — „Create Invoice" dintr-o comandă `confirmed`/`fulfilled` (specs.md §12.1,
 * plan §11). Singurul loc care generează `invoice_number` și scrie prima linie a
 * facturii, la fel cum `ConfirmOrderAction` e singurul loc care scrie `order_number`
 * (BR-ORD-02) — TIPARUL DE NUMEROTARE de mai jos e o copie deliberată a acelui fișier,
 * nu o reinventare: aceeași invariantă („unic per tenant, generat la o tranziție, nu la
 * creare"), deci aceeași soluție.
 *
 * §11.2 pas 7: „decuplat de starea de onorare" — de-asta verificarea e pe `status`, nu
 * pe `quantity_fulfilled`. `partially_fulfilled` e EXCLUS deliberat: Gherkin-ul
 * US-BILL-01 din specs.md §12.1 numește explicit doar „confirmed sau fulfilled" — vezi
 * CONTRAZICERI din raportul livrat, nu o omisiune.
 */
final class CreateInvoiceAction
{
    /**
     * Factura pornește de la un număr diferit de cel al comenzilor (`ConfirmOrderAction`
     * pornește la 10000) — continuă exact numerotarea seed-ului de demo
     * (`Database\Seeders\Demo\BillingSeeder`: `$invoiceNumber = 5000`), ca prima factură
     * creată manual pe un tenant fără date de seed să nu coincidă din întâmplare cu vreo
     * convenție de comandă.
     */
    private const FIRST_SEQUENCE = 5000;

    public function execute(Order $order): Invoice
    {
        return DB::transaction(function () use ($order): Invoice {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Confirmed, OrderStatus::Fulfilled], true)) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.invoices.invalid_status_for_creation', ['status' => $locked->status->label()]),
                ]);
            }

            // §12.1 — „Relație 1:1 în MVP": o comandă poate primi o factură NOUĂ doar dacă
            // n-are deja una activă. BR-BILL-01 permite înlocuirea unei facturi emise
            // (void + una nouă) — o factură `void` NU blochează o creare ulterioară,
            // pentru trasabilitate (rândul vechi rămâne, cu istoricul lui de plăți).
            $hasActiveInvoice = Invoice::query()
                ->where('order_id', $locked->getKey())
                ->where('status', '!=', Invoice::STATUS_VOID)
                ->exists();

            if ($hasActiveInvoice) {
                throw ValidationException::withMessages([
                    'order_id' => trans('rules.invoices.already_has_active'),
                ]);
            }

            $total = (float) $locked->grand_total;

            $invoice = new Invoice([
                'order_id' => $locked->getKey(),
                'invoice_number' => $this->nextInvoiceNumber(),
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
                'currency' => $locked->currency,
                // §12.1 — `tax_total` „generic (fără regim fiscal specific unei
                // jurisdicții — §2.3)": nicio regulă de calcul fiscal nu e cerută în MVP,
                // deci rămâne 0 și `total` oglindește direct totalul comenzii.
                'subtotal' => $total,
                'tax_total' => 0,
                'total' => $total,
                'amount_paid' => 0,
                'balance_due' => $total,
                'pdf_status' => Invoice::PDF_STATUS_PENDING,
            ]);
            $invoice->save();

            // ADR-013 — „PDF generat la cerere" (Gherkin US-BILL-01), NICIODATĂ în cererea
            // HTTP: `after_commit` (config-ul conexiunii de coadă) garantează că jobul nu
            // pornește căutarea rândului înainte ca acest COMMIT să-l fi scris.
            GenerateInvoicePdfJob::dispatch(TenantScope::requireCurrentTenantId(), $invoice->getKey());

            return $invoice;
        });
    }

    /**
     * Copie deliberată a `App\Actions\Orders\ConfirmOrderAction::nextOrderNumber()` —
     * vezi docblock-ul AICI e mai scurt exact fiindcă raționamentul complet (de ce
     * `for no key update` și nu `lockForUpdate()`, de ce blocarea `tenants` și nu a
     * rândului `invoices`, de ce un `SELECT MAX` nou după blocare e suficient sub
     * READ COMMITTED) e deja scris o dată, acolo, și `.ai/rules/tenancy.md` îl citează
     * ca regulă generală („Blocarea unui rând părinte"). Proba de concurență urmează
     * ACELAȘI tipar deja acceptat în proiect pentru acest gen de garanție —
     * `ConfirmOrderActionTest::test_order_numbers_are_sequential_and_unique_per_tenant()`
     * și `test_confirming_locks_the_tenant_row_with_for_no_key_update()`: corectitudine
     * secvențială pe apeluri succesive + verificare directă a clauzei SQL emise, NU
     * două conexiuni coordonate manual (acel test explică de ce, citând
     * `MoveDealStageAction`) — `tests/Feature/Invoices/CreateInvoiceActionTest.php`
     * repetă exact aceleași două probe pentru `invoice_number`.
     */
    private function nextInvoiceNumber(): string
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->whereKey($tenantId)->lock('for no key update')->firstOrFail();

        $sample = Invoice::query()->whereNotNull('invoice_number')->value('invoice_number');
        $prefix = is_string($sample) && preg_match('/^(.*)-\d+$/', $sample, $matches) === 1
            ? $matches[1]
            : $this->prefixFromSlug($tenant->slug);

        $maxNumber = Invoice::query()
            ->whereNotNull('invoice_number')
            ->selectRaw("max(substring(invoice_number from '[0-9]+$')::integer) as n")
            ->value('n');

        $next = $maxNumber !== null ? ((int) $maxNumber) + 1 : self::FIRST_SEQUENCE;

        return "{$prefix}-{$next}";
    }

    private function prefixFromSlug(string $slug): string
    {
        $letters = strtoupper((string) preg_replace('/[^a-zA-Z]/', '', $slug));

        return ($letters !== '' ? substr($letters, 0, 3) : 'INV').'-INV';
    }
}
