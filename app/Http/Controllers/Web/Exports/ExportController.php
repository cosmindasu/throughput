<?php

namespace App\Http\Controllers\Web\Exports;

use App\Http\Controllers\Controller;
use App\Http\Resources\Exports\ExportResource;
use App\Models\BulkOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pagina de status și descărcarea unui export (§13.2). Genericul „mai multe resurse, un
 * singur mecanism": ruta nu știe dacă operația e de conturi sau, mai târziu, de contacte —
 * doar `BulkOperation` + `BulkOperationPolicy`.
 */
final class ExportController extends Controller
{
    public function show(Request $request, BulkOperation $export): Response
    {
        $this->authorize('view', $export);

        // P3 (code review), invers de `BulkOperationController::show()` — o operație de
        // SCRIERE (`reassign_owner`) deschisă prin `/exports/{id}` ar arăta `Exports/Show`
        // cu un link de descărcare care nu va exista niciodată: `result_path` rămâne
        // `null` pentru orice `action` ÎNREGISTRAT în `BulkChunkActions`, exportul fiind
        // singurul flux care-l scrie (`App\Jobs\Exports\ExportListJob`).
        abort_unless($export->action === 'export', 404);

        return Inertia::render('Exports/Show', [
            'export' => new ExportResource($export),
        ]);
    }

    public function download(BulkOperation $export): StreamedResponse
    {
        $this->authorize('download', $export);

        return Storage::disk('local')->download(
            (string) $export->result_path,
            $export->resource_type.'-export.csv',
        );
    }
}
