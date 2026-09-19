<?php

namespace App\Support\Exports;

use App\Jobs\Exports\ExportListJob;
use App\Models\BulkOperation;
use App\Support\DemoMode;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Declanșarea unui export de listă (US-CRM-03, §13.2), comună tuturor resurselor exportabile.
 * Sub `limits.export_sync_max_rows`, CSV-ul se construiește în cerere. Peste prag, se creează
 * o operație `bulk_operations` și se pune `ExportListJob` pe coada `bulk`.
 *
 * `$format` (§13.5, Orders — decizie DomPDF) — `'csv'` (implicit, comportament neschimbat
 * pentru Accounts/Contacts) sau `'pdf'`. PDF-ul NU are cale sincronă, indiferent de
 * `$total`: randarea DomPDF ține CPU-ul mai mult decât un `SELECT`, deci n-are ce căuta în
 * tranzacția cererii (ADR-013) — pornește mereu în coadă, sub propriul plafon
 * (`limits.export_pdf_max_rows`), mult mai mic decât cel al CSV-ului.
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

        if ($format === ExportFormat::Pdf) {
            $pdfCap = (int) config('throughput.limits.export_pdf_max_rows');

            if ($total > $pdfCap) {
                return back()->with('error', "This export has {$total} rows; PDF export is capped at {$pdfCap}. Use CSV for larger exports.");
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
            return back()->with('error', 'This export exceeds the demo limit and cannot be started.');
        }

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

        return redirect()->route('exports.show', $operation)->with('success', 'Export started — this page will update automatically.');
    }
}
