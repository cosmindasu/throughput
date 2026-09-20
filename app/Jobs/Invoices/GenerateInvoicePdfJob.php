<?php

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * §12.1/ADR-013 — `invoices.pdf_status` `pending -> ready`/`failed`, generat ÎN COADĂ,
 * NICIODATĂ în cererea HTTP. Job de TENANT (ADR-014 pct. 4: `tenantId` scalar, nu un
 * `Invoice` serializat — capcana din §6.3).
 *
 * **Decizia proprietarului, 2026-09-19 (nu se renegociază):** driverul e ALES EXPLICIT
 * (`->driver('dompdf')`), exact ca `App\Support\Exports\PdfExporter` și
 * `App\Support\Reports\ReportFileWriter` — NU implicitul pachetului
 * (`config('laravel-pdf.driver')`, care rămâne `browsershot`): imaginea de producție nu
 * are Chromium (ADR-019). Șablon Blade tabelar, CSS 2.1 (fără flex/grid — DomPDF nu le
 * înțelege), diacriticele pe `DejaVu Sans`.
 *
 * DOUĂ tranzacții SCURTE, ca `App\Jobs\Exports\ExportListJob` (P1-003 acolo) — NU
 * `App\Jobs\Middleware\ApplyTenantContextToJob`: randarea DomPDF ține CPU-ul mai mult
 * decât un `SELECT` (măsurat în ADR-019, 0,5-8s după numărul de rânduri), deci n-are ce
 * căuta înăuntrul unei singure tranzacții Postgres ținute pe toată durata jobului —
 * exact tipul de problemă pe care ADR-013 îl elimină din cererea HTTP, reintrodusă pe
 * altă cale dacă jobul ar înfășura totul cu middleware-ul de context. Spre deosebire de
 * `GenerateShippingLabelJob` (unde faza din mijloc e un apel EXTERN, de rețea), aici faza
 * din mijloc e randare LOCALĂ (fără I/O de rețea) — dar tot nu aparține unei tranzacții
 * Postgres deschise, pentru același motiv de fond (ADR-013: „dacă o acțiune atinge altceva
 * decât baza proprie SAU ține CPU-ul un timp semnificativ, nu aparține tranzacției cererii").
 *
 * Idempotent: verifică `pdf_status === pending` ÎNAINTE de randare și DIN NOU chiar
 * înainte de scrierea finală — o reîncercare peste o factură deja `ready`/`failed` nu
 * randează a doua oară, iar două execuții concurente ale ACELUIAȘI job (redelivrare) nu
 * se calcă pe picioare una pe alta.
 */
final class GenerateInvoicePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $tenantId,
        public string $invoiceId,
    ) {}

    public function handle(): void
    {
        /** @var Invoice|null $invoice */
        $invoice = TenantContext::run($this->tenantId, function (): ?Invoice {
            // Review (P3) — `order.orderLines` fără `.variant.product`: șablonul
            // (`invoices.pdf.invoice`) citește doar `description`/`quantity`/`unit_price`/
            // `discount`/`line_total`, deja instantaneate pe `order_lines` la creare
            // (`BuildsOrderLines`) — două interogări în plus, degeaba, per factură.
            $found = Invoice::query()
                ->with(['tenant:id,name', 'order.account', 'order.contact', 'order.orderLines'])
                ->find($this->invoiceId);

            return $found !== null && $found->pdf_status === Invoice::PDF_STATUS_PENDING ? $found : null;
        });

        if ($invoice === null) {
            return;
        }

        $path = "invoices/{$this->tenantId}/{$invoice->getKey()}.pdf";

        try {
            // Randarea — AFARA oricărei tranzacții Postgres (vezi docblock-ul clasei).
            Pdf::view('invoices.pdf.invoice', ['invoice' => $invoice])
                ->driver('dompdf')
                ->format(Format::A4)
                ->orientation(Orientation::Portrait)
                ->disk('local')
                ->save($path);
        } catch (Throwable $e) {
            $this->markFailed();
            report($e);

            return;
        }

        TenantContext::run($this->tenantId, function () use ($path): void {
            $fresh = Invoice::query()->find($this->invoiceId);

            if ($fresh !== null && $fresh->pdf_status === Invoice::PDF_STATUS_PENDING) {
                $fresh->update(['pdf_path' => $path, 'pdf_status' => Invoice::PDF_STATUS_READY]);
            }
        });
    }

    private function markFailed(): void
    {
        TenantContext::run($this->tenantId, function (): void {
            $fresh = Invoice::query()->find($this->invoiceId);

            if ($fresh !== null && $fresh->pdf_status === Invoice::PDF_STATUS_PENDING) {
                $fresh->update(['pdf_status' => Invoice::PDF_STATUS_FAILED]);
            }
        });
    }

    /**
     * Plasă de siguranță (code review P1 la `ExportListJob`, imitat aici) — un job ucis
     * abrupt (OOM, timeout) nu ajunge în niciun `catch` de mai sus. Fără asta, o factură
     * ar rămâne `pending` la infinit, iar butonul de descărcare n-ar deveni niciodată
     * activ nici măcar ca „failed" reîncercabil.
     */
    public function failed(Throwable $e): void
    {
        $this->markFailed();
        report($e);
    }
}
