<?php

namespace App\Http\Controllers\Web\Imports;

use App\Actions\Imports\CancelImportAction;
use App\Actions\Imports\CommitImportAction;
use App\Actions\Imports\CreateImportAction;
use App\Actions\Imports\RunDryRunValidationAction;
use App\Actions\Imports\UpdateImportMappingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreImportRequest;
use App\Http\Requests\Imports\UpdateImportMappingRequest;
use App\Http\Resources\Imports\ImportResource;
use App\Http\Resources\Imports\ImportRowResource;
use App\Models\Import;
use App\Models\ImportRow;
use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportErrorReportBuilder;
use App\Support\Imports\ImportFileHeaders;
use App\Support\Imports\ImportFilePath;
use App\Support\Imports\ImportTemplateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import CSV în 4 pași (§14, plan §10). Controller subțire — parsarea fișierului, validarea
 * pe chunk-uri și scrierea entităților stau în `App\Support\Imports\*`/`App\Jobs\Imports\*`;
 * aici doar orchestrare, autorizare și forma de ieșire (`Resource`-uri, niciun model brut).
 *
 * Cele 4 pași sunt UN SINGUR ecran (`Imports/Show.tsx`), cu stări succesive pe `import.status`
 * — vezi docblock-ul acelei pagini.
 */
final class ImportController extends Controller
{
    private const INVALID_ROWS_PAGE_LIMIT = 500;

    /**
     * Fără paginare pe cursor (spre deosebire de listele de volum, Accounts/Orders): un
     * import e o operație rară și deliberată, nu un flux continuu de rânduri — cele mai
     * recente 50 acoperă orice utilizare reală, fără mecanismul de listă generic.
     */
    private const RECENT_IMPORTS_LIMIT = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Import::class);

        $imports = Import::query()
            ->with('createdBy:id,name')
            ->latest()
            ->limit(self::RECENT_IMPORTS_LIMIT)
            ->get();

        return Inertia::render('Imports/Index', [
            'imports' => ImportResource::collection($imports),
            'can' => [
                'create' => $request->user()->can('create', Import::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Import::class);

        return Inertia::render('Imports/Create', [
            'resources' => collect(ImportableResources::types())
                ->map(fn (string $type) => ['value' => $type, 'label' => ImportableResources::resolve($type)->label()])
                ->values(),
            'limits' => [
                'maxFileMb' => (int) config('throughput.limits.import_max_file_mb'),
                'maxRows' => (int) config('throughput.limits.import_max_rows'),
            ],
        ]);
    }

    public function store(StoreImportRequest $request, CreateImportAction $action): RedirectResponse
    {
        $import = $action->execute(
            $request->user(),
            (string) $request->validated('resource_type'),
            $request->file('file'),
        );

        return redirect()->route('imports.show', $import)->with('success', __('flash.imports.uploaded'));
    }

    /**
     * Template CSV descărcabil per resursă (§14.1 pct. 2) — util de pe Create ȘI de pe Show
     * (Pasul 2), de-asta stă pe o rută proprie, nu legată de un import anume.
     */
    public function template(string $resourceType): HttpResponse
    {
        $this->authorize('create', Import::class);

        $resource = ImportableResources::resolve($resourceType);
        $csv = ImportTemplateBuilder::toCsvString($resource);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$resourceType.'-import-template.csv"',
        ]);
    }

    public function show(Request $request, Import $import): Response
    {
        $this->authorize('view', $import);

        $import->loadMissing('createdBy:id,name');
        $resource = ImportableResources::resolve($import->resource_type);

        $headers = null;
        $mappingSuggestions = null;

        // Pasul 2 (mapare) — antetul se citește la CERERE, nu stocat pe model (§14.1 pct. 2):
        // fișierul e sursa unică, iar o remapare mereu vede antetul curent.
        if ($import->status === Import::STATUS_UPLOADED) {
            $absolutePath = Storage::disk(ImportFilePath::DISK)->path(ImportFilePath::for($import));
            $extension = ImportFilePath::extension($import);
            $headers = ImportFileHeaders::read($absolutePath, $extension);
            $mappingSuggestions = ColumnMappingSuggester::suggest($headers, $resource->fields());
        }

        $invalidRows = null;
        $invalidRowsTruncated = false;

        if (in_array($import->status, [
            Import::STATUS_VALIDATING, Import::STATUS_VALIDATED, Import::STATUS_IMPORTING,
            Import::STATUS_COMPLETED, Import::STATUS_COMPLETED_WITH_ERRORS,
        ], true)) {
            $invalidQuery = ImportRow::query()
                ->where('import_id', $import->getKey())
                ->where('status', ImportRow::STATUS_INVALID)
                ->orderBy('row_number');

            $totalInvalid = (clone $invalidQuery)->count();
            $invalidRows = ImportRowResource::collection($invalidQuery->limit(self::INVALID_ROWS_PAGE_LIMIT)->get());
            $invalidRowsTruncated = $totalInvalid > self::INVALID_ROWS_PAGE_LIMIT;
        }

        return Inertia::render('Imports/Show', [
            'import' => new ImportResource($import),
            'fields' => collect($resource->fields())
                ->map(fn ($field) => ['key' => $field->key, 'label' => $field->label, 'required' => $field->required])
                ->values(),
            'headers' => $headers,
            'mappingSuggestions' => $mappingSuggestions,
            'invalidRows' => $invalidRows,
            'invalidRowsTruncated' => $invalidRowsTruncated,
            'templateUrl' => route('imports.template', $import->resource_type),
            'errorReportUrl' => ($import->error_rows ?? 0) > 0 ? route('imports.errors', $import) : null,
            'can' => [
                'manage' => $request->user()->can('create', Import::class),
                'cancel' => $request->user()->can('cancel', $import),
            ],
        ]);
    }

    public function updateMapping(UpdateImportMappingRequest $request, Import $import, UpdateImportMappingAction $action): RedirectResponse
    {
        $action->execute($import, (array) $request->validated('mapping'));

        return redirect()->route('imports.show', $import)->with('success', __('flash.imports.mapping_saved'));
    }

    public function runDryRun(Request $request, Import $import, RunDryRunValidationAction $action): RedirectResponse
    {
        $this->authorize('create', Import::class);

        $action->execute($import);

        return redirect()->route('imports.show', $import)->with('success', __('flash.imports.validating'));
    }

    public function commit(Request $request, Import $import, CommitImportAction $action): RedirectResponse
    {
        $this->authorize('create', Import::class);

        $action->execute($import);

        return redirect()->route('imports.show', $import)->with('success', __('flash.imports.importing'));
    }

    /**
     * P1 (review general) — calea PRINCIPALĂ de ieșire dintr-un import blocat/abandonat:
     * orice status non-terminal poate fi anulat manual, server-side (`CancelImportAction`),
     * nu doar ascuns în UI.
     */
    public function cancel(Import $import, CancelImportAction $action): RedirectResponse
    {
        $this->authorize('cancel', $import);

        $action->execute($import);

        return redirect()->route('imports.show', $import)->with('success', __('flash.imports.cancelled'));
    }

    /**
     * Raport reimportabil (§14.1 pct. 5, US-IMP-01): DOAR rândurile eșuate + coloană `error`.
     */
    public function downloadErrors(Import $import): HttpResponse
    {
        $this->authorize('view', $import);

        $csv = ImportErrorReportBuilder::toString($import);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="import-'.$import->getKey().'-errors.csv"',
        ]);
    }
}
