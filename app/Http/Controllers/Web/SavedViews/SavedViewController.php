<?php

namespace App\Http\Controllers\Web\SavedViews;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedViews\StoreSavedViewRequest;
use App\Http\Requests\SavedViews\UpdateSavedViewRequest;
use App\Http\Resources\SavedViews\SavedViewResource;
use App\Models\SavedView;
use App\Models\SavedViewDefault;
use App\Support\SavedViews\ListColumns;
use App\Support\SavedViews\SavedViewResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Vizualizări salvate — specs.md §15, FR-VIEW-01/02. JSON simplu, ca `SearchController`
 * (§ pachetul G, `.ai/rules` nu impune Inertia pentru orice endpoint): `SavedViewPicker.tsx`
 * trăiește peste `Accounts/Index`/`Deals/Index` fără să le schimbe props-urile — controller-ul
 * lor rămâne neatins, singura excepție fiind cele două linii de redirect spre implicit
 * (`SavedViewDefaultRedirect`, apelat direct din ele).
 *
 * `apply()` e SINGURA acțiune GET care schimbă pagina — un `<Link>` obișnuit, ca exportul CSV
 * din `AccountController` — restul (create/rename/delete/set-default) sunt scrieri mici,
 * consumate prin `fetch()` (resources/js/lib/api.ts) direct din picker, fără vizită Inertia:
 * lista de conturi/deals de dedesubt nu are niciun motiv să se re-randeze doar pentru că
 * meniul de vederi s-a schimbat.
 */
final class SavedViewController extends Controller
{
    public function index(Request $request, string $resourceType): JsonResponse
    {
        abort_unless(SavedViewResourceType::isSupported($resourceType), 404);
        Gate::authorize('viewAny', SavedView::class);

        $user = $request->user();

        $mine = SavedView::query()
            ->where('resource_type', $resourceType)
            ->where('user_id', $user->getKey())
            ->orderBy('name')
            ->get();

        $team = $user->can('saved_views.view_team')
            ? SavedView::query()
                ->where('resource_type', $resourceType)
                ->where('visibility', SavedView::VISIBILITY_TEAM)
                // Cele proprii apar și în „Team" dacă au fost salvate ca atare — exclusă aici,
                // ca să nu apară o dată în fiecare grup: „My views" arată TOT ce a creat
                // utilizatorul (indiferent de vizibilitate), „Team views" doar restul echipei.
                ->where('user_id', '!=', $user->getKey())
                ->orderBy('name')
                ->get()
            : collect();

        $defaultId = SavedViewDefault::query()
            ->where('user_id', $user->getKey())
            ->where('resource_type', $resourceType)
            ->value('saved_view_id');

        return response()->json([
            'mine' => SavedViewResource::collection($mine),
            'team' => SavedViewResource::collection($team),
            'defaultId' => $defaultId,
            'can' => [
                'createTeam' => Gate::allows('createTeam', SavedView::class),
            ],
        ]);
    }

    public function store(StoreSavedViewRequest $request): JsonResponse
    {
        $resourceType = $request->validated('resource_type');
        $list = SavedViewResourceType::list($resourceType);
        $listQuery = $list->fromState($request->only(['filter', 'sort']))->toArray();

        // P2-004 (code review) — vezi docblock-ul `ResourceList::pinRoleDependentFiltersForSharing()`:
        // fixează explicit „all” pe cheile de filtru cu implicit dependent de rol (`owner`),
        // ABSENTE din starea autorului, ca scopul lui EFECTIV (nu implicitul celui care
        // deschide linkul mai târziu) să supraviețuiască partajării.
        $filters = $list->pinRoleDependentFiltersForSharing($listQuery['filter']);

        // Selector de coloane (specs.md §15.1) — scrie starea CURENTĂ a ecranului
        // (`ColumnSelector`/`useListColumns`), validată prin ACEEAȘI sanitizare ca un
        // `?columns=` de pe URL, nu `defaultColumns()`: altfel un utilizator care a ascuns
        // o coloană și salvează vederea ar primi-o înapoi vizibilă la fiecare deschidere.
        $columns = ListColumns::fromState($request->validated('columns'), $resourceType);

        $savedView = new SavedView([
            'resource_type' => $resourceType,
            'name' => $request->validated('name'),
            'filters' => $filters,
            'sort' => $listQuery['sort'],
            'columns' => $columns,
            'visibility' => $request->validated('visibility'),
        ]);
        $savedView->user_id = $request->user()->getKey();
        $savedView->save();

        return response()->json(SavedViewResource::make($savedView), 201);
    }

    public function update(UpdateSavedViewRequest $request, SavedView $savedView): JsonResponse
    {
        $savedView->update($request->validated());

        return response()->json(SavedViewResource::make($savedView));
    }

    public function destroy(SavedView $savedView): JsonResponse
    {
        Gate::authorize('delete', $savedView);

        // FR-VIEW-02: nimic de curățat manual aici — `saved_view_defaults.saved_view_id`
        // are `nullOnDelete()` (migrația dedicată), deci PostgreSQL orfanizează automat
        // rândul oricui o folosea ca implicit. `SavedViewDefaultRedirect` citește orfanul
        // la următoarea lor vizită.
        $savedView->delete();

        return response()->json(null, 204);
    }

    /**
     * Singura acțiune care schimbă pagina curentă — GET obișnuit, ca un link partajat
     * (specs.md §15.2): filtrele/sortarea vederii trec din nou prin `ResourceList::fromState()`
     * a listei ȚINTĂ, deci un filtru devenit invalid între timp dispare tăcut, nu dă 500.
     */
    public function apply(SavedView $savedView): RedirectResponse
    {
        Gate::authorize('view', $savedView);

        $list = SavedViewResourceType::list($savedView->resource_type);
        $listQuery = $list->fromState(['filter' => $savedView->filters, 'sort' => $savedView->sort]);
        $columns = ListColumns::fromState($savedView->columns, $savedView->resource_type);

        return redirect()->route(SavedViewResourceType::routeName($savedView->resource_type), [
            ...$listQuery->toArray(),
            'columns' => ListColumns::toQueryValue($columns),
        ]);
    }

    /**
     * Set/scoate implicitul PERSONAL pentru o listă (FR-VIEW-02). `saved_view_id = null`
     * ȘTERGE rândul — diferit de orfanizarea automată din `destroy()`: acolo lipsa
     * vederii e un EVENIMENT de anunțat o dată, aici e o alegere explicită a
     * utilizatorului, care nu are nevoie de nicio notificare la următoarea vizită.
     */
    public function setDefault(Request $request, string $resourceType): JsonResponse
    {
        abort_unless(SavedViewResourceType::isSupported($resourceType), 404);

        $validated = Validator::make($request->all(), [
            'saved_view_id' => ['nullable', 'string'],
        ])->validate();

        $user = $request->user();

        $default = SavedViewDefault::query()
            ->where('user_id', $user->getKey())
            ->where('resource_type', $resourceType)
            ->first();

        if ($validated['saved_view_id'] === null) {
            $default?->delete();

            return response()->json(['defaultId' => null]);
        }

        // Găsit prin Eloquent (global scope + RLS), NU `Rule::exists` pe query builder brut
        // (.ai/rules/tenancy.md): un id din alt tenant trebuie să dea 404, nu doar „nevalid".
        $savedView = SavedView::query()->where('resource_type', $resourceType)->findOrFail($validated['saved_view_id']);
        Gate::authorize('view', $savedView);

        if ($default !== null) {
            $default->update(['saved_view_id' => $savedView->getKey()]);
        } else {
            $default = new SavedViewDefault(['resource_type' => $resourceType, 'saved_view_id' => $savedView->getKey()]);
            $default->user_id = $user->getKey();
            $default->save();
        }

        return response()->json(['defaultId' => $savedView->getKey()]);
    }
}
