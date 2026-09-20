<?php

namespace Tests\Feature\Invoices;

use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkConcurrencyGuard;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;
use ZipArchive;

/**
 * FR-BILL-03 — „Export facturi în masă (PDF zip sau CSV sumar)", prin mecanismul din §13
 * (nu un al doilea mecanism de export): aceeași operație `bulk_operations`, aceeași coadă
 * `bulk`, aceeași pagină de status, același link cu expirare.
 */
class InvoiceBulkExportTest extends TestCase
{
    use CreatesInvoices;

    private Tenant $marlin;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'credit_terms' => 'net_30']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    /** „CSV sumar" — sub pragul sincron, exact ca la Accounts/Contacts/Orders: fișierul vine în cerere. */
    public function test_the_csv_summary_contains_exactly_the_rows_on_screen(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->invoiceWithPdf('INV-000001', Invoice::STATUS_SENT);
            $this->invoiceWithPdf('INV-000002', Invoice::STATUS_PAID);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/invoices/export?filter[status]=sent');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $lines = array_values(array_filter(explode("\n", trim($response->getContent()))));

        $this->assertCount(2, $lines, 'Antet + exact factura `sent`, nu și cea plătită.');
        $this->assertStringContainsString('Invoice number', $lines[0]);
        $this->assertStringContainsString('INV-000001', $lines[1]);
    }

    /**
     * BR-BULK-03, §7.4 nota ³ — Viewer-ul EXPORTĂ. Contenția vine din limita de 3 operații
     * concurente (§22.5), nu dintr-un refuz de rol.
     */
    public function test_a_viewer_can_export_invoices(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, fn () => $this->invoiceWithPdf('INV-000003', Invoice::STATUS_SENT));
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get('/marlin/invoices/export')->assertOk();
    }

    /** Arhiva ZIP: PDF-urile DEJA generate, plus un index care spune ce lipsește și de ce. */
    public function test_the_zip_export_archives_the_generated_invoice_pdfs(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->invoiceWithPdf('INV-000010', Invoice::STATUS_SENT);
            $this->invoiceWithPdf('INV-000011', Invoice::STATUS_SENT);
            // A treia are PDF-ul încă în lucru — arhiva NU o așteaptă și nu o ascunde.
            $this->invoiceWithoutPdf('INV-000012', Invoice::PDF_STATUS_PENDING);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get('/marlin/invoices/export?format=zip')
            ->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->sole());

        $this->assertSame('invoices', $operation->resource_type);
        $this->assertSame('export', $operation->action);
        $this->assertSame('zip', $operation->filter_snapshot['format']);
        $this->assertSame(BulkOperation::STATUS_PENDING, $operation->status);

        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->sole());

        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status, (string) $operation->error_message);
        $this->assertStringEndsWith('.zip', (string) $operation->result_path);
        Storage::disk('local')->assertExists($operation->result_path);

        $entries = $this->zipEntries(Storage::disk('local')->path($operation->result_path));

        $this->assertContains('INV-000010.pdf', $entries);
        $this->assertContains('INV-000011.pdf', $entries);
        $this->assertContains('contents.txt', $entries);
        $this->assertNotContains('INV-000012.pdf', $entries);

        $index = $this->zipEntryContents(Storage::disk('local')->path($operation->result_path), 'contents.txt');

        $this->assertStringContainsString('Invoices matched by the filter: 3', $index);
        $this->assertStringContainsString('PDF files included: 2', $index);
        $this->assertStringContainsString('INV-000012', $index);
        $this->assertStringContainsString('still being generated', $index);
    }

    /** Un filtru care nu potrivește nimic e un caz normal: arhivă validă, cu index care o explică. */
    public function test_an_empty_filter_still_produces_a_readable_archive(): void
    {
        $this->actingAs($this->owner)->get('/marlin/invoices/export?format=zip')->assertRedirect();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->sole());

        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status, (string) $operation->error_message);

        $index = $this->zipEntryContents(Storage::disk('local')->path($operation->result_path), 'contents.txt');
        $this->assertStringContainsString('Invoices matched by the filter: 0', $index);
    }

    /**
     * §13.2 pct. 10 — plafonul formatelor grele (`EXPORT_PDF_MAX_ROWS`, coborât la 250 după
     * măsurătorile pe container). Arhiva îl respectă, cu mesaj care direcționează spre CSV.
     */
    public function test_the_zip_export_respects_the_heavy_format_row_cap(): void
    {
        config(['throughput.limits.export_pdf_max_rows' => 1]);

        TenantContext::run($this->marlin, function (): void {
            $this->invoiceWithPdf('INV-000020', Invoice::STATUS_SENT);
            $this->invoiceWithPdf('INV-000021', Invoice::STATUS_SENT);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->from('/marlin/invoices')
            ->get('/marlin/invoices/export?format=zip')
            ->assertRedirect('/marlin/invoices')
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'capped at 1')
                && str_contains($message, 'Use CSV'));

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    /** `?format=zip` pe o listă fără fișier per rând: refuz explicit, nu o arhivă goală. */
    public function test_a_list_without_per_row_files_refuses_the_zip_format(): void
    {
        $this->actingAs($this->owner)->get('/marlin/orders/export?format=zip')->assertStatus(422);
    }

    /**
     * §22.5 — limita de 3 operații concurente, ACEEAȘI pentru toate rolurile. Verificată pe
     * VIEWER, fiindcă acolo se vede clar ce spune BR-BULK-03: nu i se refuză exportul pentru
     * că e Viewer (a reușit mai sus), ci pentru că are deja trei operații în lucru.
     */
    public function test_a_queued_export_is_refused_once_three_operations_are_active(): void
    {
        $viewer = $this->makeMember($this->marlin, 'busy.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function () use ($viewer): void {
            $this->invoiceWithPdf('INV-000040', Invoice::STATUS_SENT);

            foreach ([BulkOperation::STATUS_RUNNING, BulkOperation::STATUS_RUNNING, BulkOperation::STATUS_PENDING] as $status) {
                BulkOperation::query()->create([
                    'user_id' => $viewer->getKey(),
                    'resource_type' => 'invoices',
                    'action' => 'export',
                    'filter_snapshot' => [],
                    'total_rows' => 1,
                    'status' => $status,
                ]);
            }
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->from('/marlin/invoices')
            ->get('/marlin/invoices/export?format=zip')
            ->assertRedirect('/marlin/invoices')
            ->assertSessionHas('error', BulkConcurrencyGuard::refusal());

        $this->assertSame(3, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    /**
     * …dar un CSV sub pragul sincron NU e „o operație": se construiește în cerere, nu
     * ocupă nimic pe coadă, deci nu se numără și nu e refuzat nici la plafon.
     */
    public function test_a_synchronous_csv_export_is_not_affected_by_the_concurrency_limit(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->invoiceWithPdf('INV-000050', Invoice::STATUS_SENT);

            foreach ([BulkOperation::STATUS_RUNNING, BulkOperation::STATUS_RUNNING, BulkOperation::STATUS_RUNNING] as $status) {
                BulkOperation::query()->create([
                    'user_id' => $this->owner->getKey(),
                    'resource_type' => 'invoices',
                    'action' => 'export',
                    'filter_snapshot' => [],
                    'total_rows' => 1,
                    'status' => $status,
                ]);
            }
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/invoices/export')->assertOk();
    }

    /** §12.1 — Agentul vede doar facturile comenzilor lui; exportul nu are voie să-i dea mai mult. */
    public function test_an_agent_exports_only_invoices_of_their_own_orders(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($agent): void {
            $this->invoiceWithPdf('INV-000030', Invoice::STATUS_SENT, $this->owner);
            $this->invoiceWithPdf('INV-000031', Invoice::STATUS_SENT, $agent);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($agent)->get('/marlin/invoices/export');

        $response->assertOk();
        $this->assertStringContainsString('INV-000031', $response->getContent());
        $this->assertStringNotContainsString('INV-000030', $response->getContent());
    }

    private function invoiceWithPdf(string $number, string $status, ?User $orderOwner = null): Invoice
    {
        $order = $this->confirmedOrder($this->account, $orderOwner ?? $this->owner, 500.0);

        $invoice = $this->sentInvoice($order, 500.0);
        $path = "invoices/{$this->marlin->getKey()}/{$invoice->getKey()}.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 fake');

        $invoice->update([
            'invoice_number' => $number,
            'status' => $status,
            'pdf_path' => $path,
            'pdf_status' => Invoice::PDF_STATUS_READY,
        ]);

        return $invoice;
    }

    private function invoiceWithoutPdf(string $number, string $pdfStatus): Invoice
    {
        $order = $this->confirmedOrder($this->account, $this->owner, 500.0);

        $invoice = $this->sentInvoice($order, 500.0);
        $invoice->update(['invoice_number' => $number, 'pdf_path' => null, 'pdf_status' => $pdfStatus]);

        return $invoice;
    }

    /** @return list<string> */
    private function zipEntries(string $absolutePath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolutePath) === true, 'Arhiva nu se poate deschide.');

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $entries;
    }

    private function zipEntryContents(string $absolutePath, string $entry): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolutePath) === true, 'Arhiva nu se poate deschide.');

        $contents = (string) $zip->getFromName($entry);

        $zip->close();

        return $contents;
    }
}
