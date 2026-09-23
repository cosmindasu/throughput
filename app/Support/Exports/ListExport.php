<?php

namespace App\Support\Exports;

use App\Jobs\Exports\ExportListJob;
use App\Models\BulkOperation;
use App\Support\Bulk\BulkConcurrencyGuard;
use App\Support\DemoMode;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Declanșarea unui export de listă (US-CRM-03, §13.2), comună tuturor resurselor exportabile.
 * Sub `limits.export_sync_max_rows`, CSV-ul se construiește în cerere. Peste prag, se creează
 * o operație `bulk_operations` și se pune `ExportListJob` pe coada `bulk`.
 *
 * `$format` (§13.5, Orders — decizie DomPDF) — `'csv'` (implicit, comportament neschimbat
 * pentru Accounts/Contacts), `'pdf'` sau, din Faza 5 (FR-BILL-03), `'zip'`. Nici PDF-ul,
 * nici arhiva n-au cale sincronă, indiferent de `$total`: randarea DomPDF ține CPU-ul mai
 * mult decât un `SELECT`, iar arhiva citește zeci de fișiere de pe disc — deci niciuna n-are
 * ce căuta în tranzacția cererii (ADR-013). Amândouă pornesc în coadă, sub plafonul
 * `limits.export_pdf_max_rows`, mult mai mic decât cel al CSV-ului.
 *
 * Extrasă din AccountController când contactele au devenit a doua resursă exportabilă: două
 * copii ale acestei logici (pragul, plafonul DEMO_MODE, snapshot-ul filtrului) ar fi divergent
 * la prima schimbare. Autorizarea rămâne în controller, pe Policy-ul resursei.
 */
final class ListExport
{
    public function respond(Request $request, string $resourceType, ExportFormat $format = ExportFormat::Csv): RedirectResponse|Response
    {
        $list = ExportableResources::resolve($resourceType);
        $listQuery = $list->parse($request);
        $user = $request->user();
        $query = $list->query($listQuery, $user);

        $total = (clone $query)->toBase()->getCountForPagination();

        // FR-BILL-03 — arhiva ZIP: doar pentru listele care CHIAR au câte un fișier per rând.
        // Refuz explicit, nu o arhivă goală, dacă cineva cere `?format=zip` pe altă resursă
        // (același principiu ca refuzul unui format necunoscut în `ExportFormat::fromRequest()`).
        //
        // I18N-09 (audit 2026-09-23) — literalul englez de aici ajungea neschimbat în
        // răspunsul JSON al lui Laravel: `HttpException::getMessage()` e SINGURA excepție
        // pe care `Handler::convertExceptionToArray()` o expune și cu `app.debug=false`
        // (`isHttpException($e) ? $e->getMessage() : 'Server Error'`, verificat în
        // `vendor/laravel/framework/.../Exceptions/Handler.php`) — spre deosebire de un
        // `RuntimeException`/`InvalidArgumentException` oarecare, mascat de mesajul generic.
        // Mutat pe `__()`, ca la `ExportFormat::fromRequest()` mai sus în ierarhie.
        if ($format === ExportFormat::Zip && ! $list instanceof ArchivableList) {
            throw new HttpException(422, __('exports.errors.zip_not_supported'));
        }

        if ($format === ExportFormat::Pdf || $format === ExportFormat::Zip) {
            $pdfCap = (int) config('throughput.limits.export_pdf_max_rows');

            if ($total > $pdfCap) {
                // Același plafon pentru ambele formate, din motive DIFERITE, deci merită
                // spus: la PDF, DomPDF materializează toate rândurile în memorie (250 =
                // ~162 MB RSS măsurat pe container, plan §3). La ZIP, memoria nu crește cu
                // numărul de fișiere (`ZipArchive` comprimă în flux la `close()`) — ce
                // crește e DURATA și dimensiunea arhivei. O a doua cheie de config, doar
                // pentru asta, ar fi un buton în plus fără o măsurătoare în spate; când
                // apare una, se desparte.
                //
                // FR-I18N-04 — `:format` NU e tradus (valoare tehnică, `ExportFormat::value`,
                // identică cu parametrul de URL `?format=`). Pluralizat pe `:total`
                // (`trans_choice()`, nu ternar/`Str::plural()`): capcana „0/1" pe franceză.
                return back()->with('error', trans_choice('flash.exports.pdf_row_cap_exceeded', $total, [
                    'total' => $total,
                    'format' => $format->value,
                    'cap' => $pdfCap,
                ]));
            }

            return $this->startQueuedExport($request, $resourceType, $listQuery, $total, $format);
        }

        if ($total <= (int) config('throughput.limits.export_sync_max_rows')) {
            // NU `response()->streamDownload()`: callback-ul lui rulează DUPĂ ce răspunsul a
            // fost trimis, adică după închiderea tranzacției cererii — fără context de tenant
            // (`.ai/rules/tenancy.md`). Sub prag fișierul e mic, deci se construiește întreg.
            return response(CsvExporter::toString($list, $query), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$resourceType.'-'.now()->format('Y-m-d-His').'.csv"',
            ]);
        }

        return $this->startQueuedExport($request, $resourceType, $listQuery, $total, $format);
    }

    private function startQueuedExport(Request $request, string $resourceType, ListQuery $listQuery, int $total, ExportFormat $format): RedirectResponse
    {
        if (DemoMode::exceedsBulkRowCap($total)) {
            return back()->with('error', __('flash.exports.demo_limit_exceeded'));
        }

        // §22.5 — 3 operații în masă concurente per utilizator, ACEEAȘI limită pentru toate
        // rolurile, inclusiv pe exportul Viewer-ului (BR-BULK-03: exportul e o citire
        // permisă, iar contenția vine de AICI, nu dintr-un refuz de rol). Verificată doar pe
        // calea care chiar creează un rând `bulk_operations` — un CSV sub pragul sincron se
        // construiește în cerere, nu ocupă nicio operație, deci nu se numără și nu e refuzat.
        //
        // `back()->with('error')`, nu `ValidationException`: linkul de export e o ancoră
        // `<a href>`, nu un formular — un 422 cu erori de câmp n-ar avea unde să apară
        // (același raționament ca la `ExportFormat::fromRequest()`).
        if (BulkConcurrencyGuard::hasReachedLimit($request->user())) {
            return back()->with('error', BulkConcurrencyGuard::refusal());
        }

        // Notă (raportul lotului) — `BulkConcurrencyGuard::refusal()` întoarce acum un
        // string TRADUS (`trans_choice('rules.bulk.concurrency_limit', ...)`), nu
        // literalul englez de dinainte: schimbarea a fost făcută de un alt agent, în
        // paralel, pe catalogul lui (`rules.*`, domeniul `ValidationException`), fiindcă
        // `BulkConcurrencyGuard::refusal()` are doi apelanți — flash-ul de aici ȘI o
        // `ValidationException::withMessages()` din `EnsureBulkConcurrencyLimit`. Flash-ul
        // beneficiază tranzitiv, fără nicio schimbare necesară în ACEST fișier.

        $operation = BulkOperation::create([
            'user_id' => $request->user()->getKey(),
            'resource_type' => $resourceType,
            'action' => 'export',
            // `user_id` e sursa de adevăr pentru autor; snapshot-ul ține filtrul, sortarea
            // și formatul ales — `ResourceList::fromState()` citește doar `filter`/`sort`
            // din acest array și ignoră restul (`format` inclus), nicio coliziune de chei.
            'filter_snapshot' => [...$listQuery->toArray(), 'format' => $format->value],
            'total_rows' => $total,
            'status' => BulkOperation::STATUS_PENDING,
        ]);

        ExportListJob::dispatch(app('tenant')->getKey(), $operation->getKey())->onQueue('bulk');

        return redirect()->route('exports.show', $operation)->with('success', __('flash.exports.started'));
    }
}
