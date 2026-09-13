<?php

namespace App\Support\Exports;

use App\Jobs\Exports\ExportListJob;
use App\Models\BulkOperation;
use App\Support\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Declanșarea unui export de listă (US-CRM-03, §13.2), comună tuturor resurselor exportabile.
 * Sub `limits.export_sync_max_rows`, CSV-ul se construiește în cerere. Peste prag, se creează
 * o operație `bulk_operations` și se pune `ExportListJob` pe coada `bulk`.
 *
 * Extrasă din AccountController când contactele au devenit a doua resursă exportabilă: două
 * copii ale acestei logici (pragul, plafonul DEMO_MODE, snapshot-ul filtrului) ar fi divergent
 * la prima schimbare. Autorizarea rămâne în controller, pe Policy-ul resursei.
 */
final class ListExport
{
    public function respond(Request $request, string $resourceType): RedirectResponse|Response
    {
        $list = ExportableResources::resolve($resourceType);
        $listQuery = $list->parse($request);
        $user = $request->user();
        $query = $list->query($listQuery, $user);

        $total = (clone $query)->toBase()->getCountForPagination();

        if ($total <= (int) config('throughput.limits.export_sync_max_rows')) {
            // NU `response()->streamDownload()`: callback-ul lui rulează DUPĂ ce răspunsul a
            // fost trimis, adică după închiderea tranzacției cererii — fără context de tenant
            // (`.ai/rules/tenancy.md`). Sub prag fișierul e mic, deci se construiește întreg.
            return response(CsvExporter::toString($list, $query), 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$resourceType.'-'.now()->format('Y-m-d-His').'.csv"',
            ]);
        }

        if (DemoMode::exceedsBulkRowCap($total)) {
            return back()->with('error', 'This export exceeds the demo limit and cannot be started.');
        }

        $operation = BulkOperation::create([
            'user_id' => $user->getKey(),
            'resource_type' => $resourceType,
            'action' => 'export',
            // `user_id` e sursa de adevăr pentru autor; snapshot-ul ține doar filtrul și sortarea.
            'filter_snapshot' => $listQuery->toArray(),
            'total_rows' => $total,
            'status' => BulkOperation::STATUS_PENDING,
        ]);

        ExportListJob::dispatch(app('tenant')->getKey(), $operation->getKey())->onQueue('bulk');

        return redirect()->route('exports.show', $operation)->with('success', 'Export started — this page will update automatically.');
    }
}
