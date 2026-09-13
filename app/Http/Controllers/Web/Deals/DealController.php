<?php

namespace App\Http\Controllers\Web\Deals;

use App\Actions\Deals\CreateDealAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreDealRequest;
use App\Http\Requests\Deals\UpdateDealRequest;
use App\Http\Resources\DealContactOptionResource;
use App\Http\Resources\DealOwnerOptionResource;
use App\Http\Resources\DealResource;
use App\Http\Resources\DealStageEventResource;
use App\Http\Resources\DealStageResource;
use App\Http\Resources\DealSummaryResource;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Support\Lists\DealList;
use App\Support\Permissions;
use App\Support\RecentlyViewed;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de deals + kanban board — Pachetul C (specs.md §7.3-§7.5, §9; plan §8).
 *
 * `DealStageController::move()` e SEPARAT (rută/controller propriu, `PATCH
 * /deals/{deal}/stage`): schimbarea de etapă are propria regulă de business
 * (`MoveDealStageAction`) și nu se face niciodată din formularul de Edit (§9 task —
 * „etapa NU se schimbă din formular").
 */
class DealController extends Controller
{
    public function index(Request $request, DealList $list): Response
    {
        Gate::authorize('viewAny', Deal::class);

        $user = $request->user();
        $query = $list->parse($request);
        $paginator = $query->paginate($list->query($query, $user));

        return Inertia::render('Deals/Index', [
            // Deferred (Inertia 3 — carry-over din v2, cerut de FR-PERF-01): shell-ul
            // paginii (titlu, filtre, acțiuni) e pe ecran înainte ca rândurile să vină.
            'deals' => Inertia::defer(fn () => [
                'data' => DealSummaryResource::collection($paginator->items()),
                'nextCursor' => $paginator->nextCursor()?->encode(),
                'prevCursor' => $paginator->previousCursor()?->encode(),
            ]),
            'filters' => $query->toArray(),
            'can' => [
                'create' => Gate::allows('create', Deal::class),
            ],
        ]);
    }

    /**
     * US-DEAL-01 — precompletat din `?account=`. Deal-urile se creează DIN pagina unui
     * cont (URL convenit: `/{w}/deals/create?account={id}`), nu dintr-un formular
     * generic cu selector de cont — Accounts (căutare/listă) e alt pachet, încă
     * nemerge în acest branch.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Deal::class);

        $account = Account::query()->findOrFail($request->query('account'));

        return Inertia::render('Deals/Create', [
            'account' => ['id' => $account->id, 'name' => $account->name],
            'contacts' => DealContactOptionResource::collection(
                $account->contacts()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ),
            'can' => ['changeOwner' => Gate::allows('changeOwner', Deal::class)],
            'owners' => Gate::allows('changeOwner', Deal::class) ? $this->ownerOptions() : [],
        ]);
    }

    public function store(StoreDealRequest $request, CreateDealAction $action): RedirectResponse
    {
        Gate::authorize('create', Deal::class);

        $data = $request->validated();

        if (! Gate::allows('changeOwner', Deal::class)) {
            unset($data['owner_user_id']);
        }

        $deal = $action->execute($data, $request->user());

        return redirect()->route('deals.show', $deal)->with('success', 'Deal created.');
    }

    public function show(Request $request, Deal $deal): Response
    {
        Gate::authorize('view', $deal);

        RecentlyViewed::record($request, 'deal', $deal->getKey(), $deal->title, route('deals.show', $deal));

        $deal->load([
            'account:id,name',
            'primaryContact:id,first_name,last_name',
            'pipeline:id,name',
            'stage:id,name,is_won,is_lost',
            'owner:id,name',
        ]);

        $events = $deal->stageEvents()
            ->with(['fromStage:id,name', 'toStage:id,name', 'changedBy:id,name'])
            ->orderByDesc('changed_at')
            ->get();

        return Inertia::render('Deals/Show', [
            'deal' => DealResource::make($deal),
            'stageEvents' => DealStageEventResource::collection($events),
            'stages' => DealStageResource::collection($this->pipelineStages($deal->pipeline_id)),
            'can' => [
                'edit' => Gate::allows('update', $deal),
                'delete' => Gate::allows('delete', $deal),
                'moveStage' => Gate::allows('moveStage', $deal),
                'changeOwner' => Gate::allows('changeOwner', $deal),
            ],
        ]);
    }

    public function edit(Deal $deal): Response
    {
        Gate::authorize('update', $deal);

        $deal->load(['account:id,name', 'primaryContact:id,first_name,last_name']);

        return Inertia::render('Deals/Edit', [
            'deal' => DealResource::make($deal),
            'contacts' => DealContactOptionResource::collection(
                $deal->account->contacts()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ),
            'can' => [
                'changeOwner' => Gate::allows('changeOwner', $deal),
                'delete' => Gate::allows('delete', $deal),
            ],
            'owners' => Gate::allows('changeOwner', $deal) ? $this->ownerOptions() : [],
        ]);
    }

    public function update(UpdateDealRequest $request, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);

        $data = $request->validated();

        $deal->title = $data['title'];
        $deal->value = $data['value'] ?? null;
        $deal->expected_close_date = $data['expected_close_date'] ?? null;
        $deal->primary_contact_id = $data['primary_contact_id'] ?? null;

        if (! empty($data['currency'])) {
            $deal->currency = $data['currency'];
        }

        if (Gate::allows('changeOwner', $deal) && ! empty($data['owner_user_id'])) {
            $deal->owner_user_id = $data['owner_user_id'];
        }

        $deal->save();

        return redirect()->route('deals.show', $deal)->with('success', 'Deal updated.');
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        Gate::authorize('delete', $deal);

        $deal->delete();

        return redirect()->route('deals.index')->with('success', 'Deal deleted.');
    }

    /**
     * `Deals/Kanban` — coloane = etapele pipeline-ului implicit, după `position`
     * (§9.3). Plafon de 50 carduri per coloană (cele mai recente); `hasMore` duce la
     * lista filtrată pe etapă („View all").
     */
    public function board(Request $request): Response
    {
        Gate::authorize('viewAny', Deal::class);

        $user = $request->user();
        $restricted = Permissions::restrictedToOwnRecords($user);
        $ownerFilter = $request->query('owner', $restricted ? 'me' : 'all');
        $ownerFilter = in_array($ownerFilter, ['me', 'all'], true) ? $ownerFilter : ($restricted ? 'me' : 'all');

        $pipeline = Pipeline::query()->where('is_default', true)->first()
            ?? Pipeline::query()->oldest('created_at')->first();

        abort_if($pipeline === null, 404);

        $stages = Stage::query()->where('pipeline_id', $pipeline->getKey())->orderBy('position')->get();

        $columns = $stages->map(function (Stage $stage) use ($ownerFilter, $user) {
            $base = Deal::query()->where('stage_id', $stage->getKey());

            if ($ownerFilter === 'me') {
                $base->where('owner_user_id', $user->getKey());
            }

            $total = (clone $base)->count();

            $deals = (clone $base)
                ->with(['account:id,name', 'owner:id,name', 'stage:id,name,is_won,is_lost'])
                ->latest('created_at')
                ->limit(50)
                ->get();

            return [
                'stage' => DealStageResource::make($stage),
                'deals' => DealSummaryResource::collection($deals),
                'total' => $total,
                'hasMore' => $total > $deals->count(),
            ];
        });

        return Inertia::render('Deals/Kanban', [
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'columns' => $columns,
            'ownerFilter' => $ownerFilter,
            'can' => [
                'create' => Gate::allows('create', Deal::class),
                'managePipeline' => $user->can('pipelines.manage'),
            ],
        ]);
    }

    private function ownerOptions(): AnonymousResourceCollection
    {
        return DealOwnerOptionResource::collection(
            Membership::query()->where('status', Membership::STATUS_ACTIVE)->with('user:id,name')->get()
        );
    }

    /**
     * @return EloquentCollection<int, Stage>
     */
    private function pipelineStages(string $pipelineId): EloquentCollection
    {
        return Stage::query()->where('pipeline_id', $pipelineId)->orderBy('position')->get();
    }
}
