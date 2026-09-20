<?php

namespace Tests\Feature\Exports;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * FR-I18N-04, ADR-022 — capcana centrală a lotului: `Str::plural()` (înlocuit acum cu
 * `trans_choice()` în `exports/pdf/list.blade.php` și `reports/pdf/built-in.blade.php`)
 * aplică regula engleză de pluralizare, care tratează 0 ca PLURAL. Franceza tratează 0 CA
 * SINGULAR (`Illuminate\Translation\MessageSelector::getPluralIndex('fr', ...)`, verificat
 * direct în `vendor/laravel/framework`: `(($number == 0) || ($number == 1)) ? 0 : 1`) — un
 * rând „0 items" pe un PDF gol era exact locul unde regula greșită s-ar fi văzut, pentru un
 * cititor francofon. Fiecare treaptă (0/1/N) e testată separat, pe ambele limbi — nu doar
 * 1 și N (cerință explicită a task-ului).
 *
 * Randează șabloanele Blade DIRECT (`View::make(...)->render()`), fără să treacă prin
 * `PdfExporter`/`ReportFileWriter` (afara perimetrului acestui lot dincolo de headerele de
 * coloană) și fără DomPDF (HTML-ul randat e suficient pentru text/atribute — un test de PDF
 * real ar verifica doar că fișierul începe cu „%PDF", nu conținutul textual, vezi
 * `GenerateInvoicePdfJobTest`).
 *
 * Fiecare test își restaurează explicit `App::setLocale('en')` la final: Laravel recreează
 * `$this->app` per test (`Illuminate\Foundation\Testing\TestCase::setUp()`), deci locale-ul
 * NU scapă între metode de test — restaurarea de mai jos e totuși explicită, defensivă,
 * exact ca la orice altă stare mutabilă globală din suită (vezi `.ai/rules/tenancy.md`,
 * capcana de memoizare per-cerere).
 */
class PdfPluralizationTest extends TestCase
{
    public function test_list_export_pdf_shows_zero_rows_as_plural_in_english(): void
    {
        App::setLocale('en');

        $html = $this->renderListExportPdf(0);

        $this->assertStringContainsString('0 rows', $html);
        $this->assertStringContainsString('<html lang="en">', $html);

        App::setLocale('en');
    }

    public function test_list_export_pdf_shows_one_row_as_singular_in_english(): void
    {
        App::setLocale('en');

        $html = $this->renderListExportPdf(1);

        $this->assertStringContainsString('1 row', $html);
        $this->assertStringNotContainsString('1 rows', $html);

        App::setLocale('en');
    }

    public function test_list_export_pdf_shows_many_rows_as_plural_in_english(): void
    {
        App::setLocale('en');

        $html = $this->renderListExportPdf(3);

        $this->assertStringContainsString('3 rows', $html);

        App::setLocale('en');
    }

    /**
     * LA CAPĂT: franceza tratează 0 ca SINGULAR („0 ligne", NU „0 lignes") — exact opusul
     * regulii engleze de mai sus. `Str::plural('row', 0)` ar fi dat „rows" indiferent de
     * locale; `trans_choice()` alege corect segmentul per `App::getLocale()`.
     */
    public function test_list_export_pdf_shows_zero_rows_as_singular_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderListExportPdf(0);

        $this->assertStringContainsString('0 ligne', $html);
        $this->assertStringNotContainsString('0 lignes', $html);
        $this->assertStringContainsString('<html lang="fr">', $html);

        App::setLocale('en');
    }

    public function test_list_export_pdf_shows_one_row_as_singular_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderListExportPdf(1);

        $this->assertStringContainsString('1 ligne', $html);
        $this->assertStringNotContainsString('1 lignes', $html);

        App::setLocale('en');
    }

    public function test_list_export_pdf_shows_many_rows_as_plural_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderListExportPdf(3);

        $this->assertStringContainsString('3 lignes', $html);

        App::setLocale('en');
    }

    /**
     * Mesajul de listă goală ("Aucune ligne ne correspond à ce filtre.") e independent de
     * textul de pluralizare — verificat separat, ca cele două capcane (mesaj gol vs.
     * numărătoare la 0) să nu se mascheze una pe alta.
     */
    public function test_list_export_pdf_empty_state_message_is_translated_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderListExportPdf(0);

        $this->assertStringContainsString('Aucune ligne ne correspond à ce filtre.', $html);

        App::setLocale('en');
    }

    /** Mirror-ul pe `reports/pdf/built-in.blade.php` — șablon separat, aceeași capcană. */
    public function test_built_in_report_pdf_shows_zero_rows_as_plural_in_english(): void
    {
        App::setLocale('en');

        $html = $this->renderBuiltInReportPdf(0);

        $this->assertStringContainsString('0 rows', $html);
        $this->assertStringContainsString('<html lang="en">', $html);

        App::setLocale('en');
    }

    public function test_built_in_report_pdf_shows_zero_rows_as_singular_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderBuiltInReportPdf(0);

        $this->assertStringContainsString('0 ligne', $html);
        $this->assertStringNotContainsString('0 lignes', $html);
        $this->assertStringContainsString('<html lang="fr">', $html);

        App::setLocale('en');
    }

    public function test_built_in_report_pdf_shows_many_rows_as_plural_in_french(): void
    {
        App::setLocale('fr');

        $html = $this->renderBuiltInReportPdf(4);

        $this->assertStringContainsString('4 lignes', $html);

        App::setLocale('en');
    }

    private function renderListExportPdf(int $rowCount): string
    {
        return View::make('exports.pdf.list', [
            'workspaceName' => 'Marlin Fasteners & Supply Co.',
            'headers' => ['Name', 'Domain'],
            'rows' => array_fill(0, $rowCount, ['Acme Inc.', 'acme.test']),
            'filters' => [],
            'generatedAt' => now(),
        ])->render();
    }

    private function renderBuiltInReportPdf(int $rowCount): string
    {
        return View::make('reports.pdf.built-in', [
            'title' => 'Deal Velocity by Stage',
            'workspaceName' => 'Marlin Fasteners & Supply Co.',
            'headers' => ['Pipeline', 'Stage'],
            'rows' => array_fill(0, $rowCount, ['Sales', 'New']),
            'generatedAt' => now(),
        ])->render();
    }
}
