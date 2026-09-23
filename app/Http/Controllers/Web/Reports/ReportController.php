<?php

namespace App\Http\Controllers\Web\Reports;

use App\Enums\ReportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Http\Requests\Reports\UpdateReportRequest;
use App\Http\Resources\Reports\ReportDefinitionResource;
use App\Http\Resources\Reports\ReportRunResource;
use App\Http\Resources\SavedViews\SavedViewResource;
use App\Jobs\Reports\GenerateReportJob;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\SavedView;
use App\Models\User;
use App\Support\Exports\ExportableResources;
use App\Support\Permissions;
use App\Support\Reports\BuiltInReports;
use App\Support\Reports\ReportRecipients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapoarte și livrare programată — specs.md §16, plan §10. Controller subțire: validarea
 * stă în FormRequests, drepturile în `ReportDefinitionPolicy`, generarea propriu-zisă în
 * `App\Jobs\Reports\GenerateReportJob` (coadă) — niciun apel extern sau muncă grea aici
 * (ADR-013). Rezultatul built-in sincron (US-REP-02) e o agregare ieftină, măsurată cu
 * `EXPLAIN ANALYZE` la 3-6 ms (vezi raportul lotului K) — sigură de rulat direct în cerere.
 */
final class ReportController extends Controller
{
    /**
     * Plafon de rânduri AFIȘATE sincron în pagină pentru un raport built-in (ADR-013 —
     * dacă interogarea ar fi grea, n-ar aparține cererii HTTP; asta rămâne ieftină, dar
     * plafonul e o precauție, nu o presupunere). 200 e generos față de cardinalitatea
     * REALĂ a agregărilor de azi (etape × pipeline-uri, sau locații × categorii — sub 10
     * rânduri în seed, sub câteva zeci chiar la un tenant mult mai mare), deci plafonul nu
     * se atinge niciodată în practică — protejează doar împotriva unei creșteri neașteptate
     * a cardinalității (ex: un tenant cu zeci de pipeline-uri, Faza 2 viitoare, §3.3).
     */
    private const BUILT_IN_PREVIEW_ROW_CAP = 200;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportDefinition::class);
        $user = $request->user();

        $reports = ReportRecipients::scopeVisibleTo(
            ReportDefinition::query()->with(['savedView', 'createdBy', 'latestRun']),
            $user,
        )->orderBy('name')->get();

        return Inertia::render('Reports/Index', [
            'reports' => ReportDefinitionResource::collection($reports),
            'can' => [
                'create' => $user->can('create', ReportDefinition::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ReportDefinition::class);

        return Inertia::render('Reports/Create', [
            'savedViews' => SavedViewResource::collection($this->eligibleSavedViews($request->user())),
            'builtInReports' => $this->builtInOptions(),
        ]);
    }

    public function store(StoreReportRequest $request): RedirectResponse
    {
        $report = new ReportDefinition($this->fillableFromValidated($request->validated()));
        $report->created_by = $request->user()->getKey();
        $report->save();

        return redirect()->route('reports.show', $report)->with('success', __('flash.reports.created'));
    }

    public function show(Request $request, ReportDefinition $report): Response
    {
        $this->authorize('view', $report);
        $user = $request->user();

        $report->load(['savedView', 'createdBy', 'latestRun']);
        $runs = $report->reportRuns()->orderByDesc('id')->limit(20)->get();

        return Inertia::render('Reports/Show', [
            'report' => new ReportDefinitionResource($report),
            'runs' => ReportRunResource::collection($runs),
            // US-REP-02 — „văd rezultatul direct în interfață (fără să aștept email)":
            // randat mereu proaspăt la fiecare vizită a paginii pentru un raport built-in,
            // NU doar imediat după „Run now" — evită orice mecanism efemer (sesiune/flash)
            // pentru date structurate, iar `flash` (App\Http\Middleware\HandleInertiaRequests,
            // fișier interzis acestui lot) are oricum o formă fixă (`success`/`error`/`notice`).
            'builtInPreview' => $report->isBuiltIn() ? $this->builtInPreview($report, $user) : null,
            'can' => [
                'update' => $user->can('update', $report),
                'delete' => $user->can('delete', $report),
                'runNow' => $user->can('runNow', $report),
                'download' => $user->can('download', $report),
            ],
        ]);
    }

    public function edit(Request $request, ReportDefinition $report): Response
    {
        $this->authorize('update', $report);

        return Inertia::render('Reports/Edit', [
            'report' => new ReportDefinitionResource($report->load('savedView')),
            'savedViews' => SavedViewResource::collection($this->eligibleSavedViews($request->user())),
            'builtInReports' => $this->builtInOptions(),
        ]);
    }

    public function update(UpdateReportRequest $request, ReportDefinition $report): RedirectResponse
    {
        $report->update($this->fillableFromValidated($request->validated()));

        return redirect()->route('reports.show', $report)->with('success', __('flash.reports.updated'));
    }

    public function destroy(ReportDefinition $report): RedirectResponse
    {
        $this->authorize('delete', $report);

        $report->delete();

        return redirect()->route('reports.index')->with('success', __('flash.reports.deleted'));
    }

    /**
     * US-REP-02 — „Run now": crește un `report_runs` (`triggered_by = manual`) și
     * dispecerizează `GenerateReportJob`, exact ca scheduler-ul (§16.2 pct. 2), doar cu alt
     * `triggered_by`. Nu calculează nimic sincron aici — vezi `show()`/`builtInPreview()`.
     */
    public function runNow(Request $request, ReportDefinition $report): RedirectResponse
    {
        $this->authorize('runNow', $report);

        $run = ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_QUEUED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
        ]);

        GenerateReportJob::dispatch(app('tenant')->getKey(), $run->getKey())->onQueue('default');

        return redirect()->route('reports.show', $report)->with('success', __('flash.reports.queued'));
    }

    /**
     * Descărcare binară (§13.2 nota din task — un `<a href>` simplu pe front, NU un
     * `<Link>` Inertia, la fel ca `ExportController::download()`/`Exports/Show.tsx`).
     */
    public function download(ReportDefinition $report, ReportRun $run): StreamedResponse
    {
        $this->authorize('download', $report);
        abort_unless($run->report_definition_id === $report->getKey(), 404);
        abort_unless($run->status === ReportRun::STATUS_SUCCESS && $run->file_path !== null, 404);

        $format = ReportFormat::from($report->format);

        return Storage::disk('local')->download($run->file_path, Str::slug($report->name).'.'.$format->extension());
    }

    /**
     * Fix P1 (review) — `variants.cost` e ascuns pentru Agent/Viewer în restul aplicației
     * (`Permissions::canViewCost()`, §7.4); un Agent destinatar al unui Inventory
     * Valuation putea deschide `Reports/Show` și vedea `on_hand × cost` direct în pagină,
     * necondiționat. Previzualizarea (NU tot ecranul, NU fișierul descărcabil — decizie
     * separată, vezi `InventoryValuationReport`) se ascunde pentru cine nu are
     * `canViewCost()`, cu un motiv explicit în loc de tabel.
     *
     * @return array{columns: list<string>, rows: list<list<string|int|float|null>>, totalRows: int, truncated: bool, hiddenForCost: bool}
     */
    private function builtInPreview(ReportDefinition $report, User $user): array
    {
        $builtIn = BuiltInReports::resolve($report->report_type);

        if ($builtIn->exposesCost() && ! Permissions::canViewCost($user)) {
            return ['columns' => [], 'rows' => [], 'totalRows' => 0, 'truncated' => false, 'hiddenForCost' => true];
        }

        $rows = $builtIn->rows();

        return [
            'columns' => $builtIn->columns(),
            'rows' => array_slice($rows, 0, self::BUILT_IN_PREVIEW_ROW_CAP),
            'totalRows' => count($rows),
            'truncated' => count($rows) > self::BUILT_IN_PREVIEW_ROW_CAP,
            'hiddenForCost' => false,
        ];
    }

    /**
     * Vederile salvate eligibile ca sursă de raport (§16.1, `saved_view_export`): ale
     * utilizatorului + cele de echipă, DAR doar pe resurse suportate azi de mecanismul de
     * export (`ExportableResources`) — la fel ca validarea din `StoreReportRequest`, ca
     * formularul să nu ofere o alegere pe care serverul ar respinge-o oricum.
     *
     * @return Collection<int, SavedView>
     */
    private function eligibleSavedViews(User $user): Collection
    {
        return SavedView::query()
            ->whereIn('resource_type', array_keys(ExportableResources::map()))
            ->where(fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->orWhere('visibility', SavedView::VISIBILITY_TEAM))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function builtInOptions(): array
    {
        return array_map(
            fn (string $type) => ['value' => $type, 'label' => BuiltInReports::resolve($type)->title()],
            array_keys(BuiltInReports::map()),
        );
    }

    /**
     * `report_type = saved_view_export` ↔ `saved_view_id` populat; orice alt tip built-in
     * ↔ `saved_view_id` nul (§16.1 — vezi nota de contradicție în raportul lotului K despre
     * formularea „nul dacă provine dintr-un saved_view" de pe rândul GREȘIT al tabelului).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function fillableFromValidated(array $validated): array
    {
        $isSavedViewExport = $validated['report_type'] === ReportDefinition::TYPE_SAVED_VIEW_EXPORT;

        return [
            ...$validated,
            'saved_view_id' => $isSavedViewExport ? $validated['saved_view_id'] : null,
            'recipients' => ReportRecipients::normalize($validated['recipients']),
            'schedule_day' => $validated['schedule_frequency'] === ReportDefinition::FREQUENCY_DAILY
                || $validated['schedule_frequency'] === ReportDefinition::FREQUENCY_NONE
                ? null
                : ($validated['schedule_day'] ?? null),
            'is_active' => $validated['is_active'] ?? true,
        ];
    }
}
