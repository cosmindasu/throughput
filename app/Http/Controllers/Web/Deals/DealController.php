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
use App\Support\SavedViews\SavedViewDefaultRedirect;
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
    public function index(Request $request, DealList $list): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Deal::class);

        // FR-VIEW-02 — vezi docblock-ul din AccountController::index() pentru raționamentul
        // complet: implicitul salvat câștigă în fața filtrului de rol, dar doar pe un URL
        // fără NIMIC explicit încă.
        if (($redirect = SavedViewDefaultRedirect::resolve($request, 'deals')) !== null) {
            return $redirect;
        }

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
     * US-DEAL-01 — precompletat din `?account=` când parametrul există (link „New deal"
     * din pagina unui cont, `Accounts/Show.tsx`). Acum că pagina de Accounts e pe branch,
     * câmpul „Account" e un `AccountCombobox` obligatoriu, nu un cont impus: fără
     * `?account=`, pagina se deschide cu câmpul gol (nu 404) și utilizatorul alege contul
     * din combobox înainte de submit.
     *
     * Un `?account=` PREZENT dar invalid (ULID din alt tenant sau inexistent) rămâne
     * `findOrFail` → 404, ca înainte — doar ABSENȚA parametrului nu mai e o eroare.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Deal::class);

        $accountId = $request->query('account');
        $account = $accountId !== null && $accountId !== '' ? Account::query()->findOrFail($accountId) : null;

        return Inertia::render('Deals/Create', [
            'account' => $account !== null ? ['id' => $account->id, 'name' => $account->name] : null,
            'contacts' => $this->contactsForAccount($account?->id),
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

    /**
     * `?account=` e opțional AICI și diferit de rolul lui pe `create()`: nu precompletează
     * nimic, doar spune cărui cont să-i afișeze contactele când formularul cere o
     * reîncărcare parțială (`AccountCombobox` → `router.get` pe aceeași pagină, cu noul
     * `account_id` ales) — vezi `resources/js/Pages/Deals/Edit.tsx`. Absența lui (prima
     * încărcare a paginii) cade pe contul curent al deal-ului. Deal-ul propriu-zis NU se
     * schimbă aici — doar la `update()`, după submit.
     */
    public function edit(Request $request, Deal $deal): Response
    {
        Gate::authorize('update', $deal);

        $deal->load(['account:id,name', 'primaryContact:id,first_name,last_name']);

        $accountId = $request->has('account') ? $request->query('account') : $deal->account_id;

        return Inertia::render('Deals/Edit', [
            'deal' => DealResource::make($deal),
            'contacts' => $this->contactsForAccount($accountId),
            'can' => [
                'changeOwner' => Gate::allows('changeOwner', $deal),
                'delete' => Gate::allows('delete', $deal),
            ],
            'owners' => Gate::allows('changeOwner', $deal) ? $this->ownerOptions() : [],
        ]);
    }

    /**
     * §9 task (Pachetul „AccountCombobox pe deal") — `account_id` e acum editabil, cu
     * aceleași reguli ca la creare (`UpdateDealRequest`: tenant curent + policy de cont).
     * Mutarea pe alt cont NU atinge `stage_id`, nu inserează `deal_stage_events` și nu
     * schimbă `owner_user_id` decât dacă `changeOwner` e permis ȘI câmpul e trimis —
     * exact ca înainte. `DealStageController::move()` rămâne singurul loc care schimbă
     * etapa.
     */
    public function update(UpdateDealRequest $request, Deal $deal): RedirectResponse
    {
        Gate::authorize('update', $deal);

        $data = $request->validated();

        $deal->account_id = $data['account_id'];
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
     *
     * Numărul de interogări e CONSTANT, indiferent de câte etape are pipeline-ul
     * (P3-d, code review): varianta veche făcea `count()` + `latest()->limit(50)` +
     * eager-load-uri PE COLOANĂ — ~25-40 interogări măsurate la 6-8 etape. Aici:
     * totalurile într-o SINGURĂ interogare cu `groupBy('stage_id')`, iar plafonul de
     * 50/etapă cu O SINGURĂ interogare fereastră (`row_number() over (partition by
     * stage_id ...)`) — echivalentul SQL al unui „limit per grup", pe care Eloquent nu-l
     * oferă nativ.
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
        $stageIds = $stages->pluck('id');

        // 1 interogare pentru TOATE totalurile (nu una per coloană).
        $totals = Deal::query()
            ->whereIn('stage_id', $stageIds)
            ->when($ownerFilter === 'me', fn ($query) => $query->where('owner_user_id', $user->getKey()))
            ->selectRaw('stage_id, count(*) as aggregate')
            ->groupBy('stage_id')
            ->pluck('aggregate', 'stage_id');

        // Rămâne pe query builder-ul lui `Deal` (nu `DB::table`), ca global scope-ul de
        // tenant să se aplice ca pe orice altă interogare Eloquent — RLS, pe aceeași
        // conexiune cu `app.tenant_id` deja setat de middleware, protejează oricum
        // indiferent de forma SQL-ului.
        $rankedDeals = Deal::query()
            ->select('deals.*')
            ->selectRaw('row_number() over (partition by stage_id order by created_at desc, id desc) as stage_rank')
            ->whereIn('stage_id', $stageIds)
            ->when($ownerFilter === 'me', fn ($query) => $query->where('owner_user_id', $user->getKey()));

        // Aliasul subquery-ului TREBUIE să fie `deals`, identic cu tabela reală:
        // `TenantScope::apply()` calchează pe `$model->getTable()` și generează
        // `"deals"."tenant_id" = ?` — cu orice alt alias, Postgres răspunde „missing
        // FROM-clause entry for table deals".
        $deals = Deal::query()
            ->fromSub($rankedDeals, 'deals')
            ->where('stage_rank', '<=', 50)
            ->with(['account:id,name', 'owner:id,name', 'stage:id,name,is_won,is_lost'])
            ->orderBy('stage_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('stage_id');

        $columns = $stages->map(function (Stage $stage) use ($totals, $deals) {
            $stageDeals = $deals->get($stage->getKey(), collect());
            $total = (int) $totals->get($stage->getKey(), 0);

            return [
                'stage' => DealStageResource::make($stage),
                'deals' => DealSummaryResource::collection($stageDeals),
                'total' => $total,
                'hasMore' => $total > $stageDeals->count(),
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
     * Contactele disponibile pentru „Primary contact", scopate la contul ales în
     * `AccountCombobox` (Create ȘI Edit — §9 task: „contactul principal depinde de
     * cont"). `find()`, nu `findOrFail()`: id-ul poate fi tranzitoriu (utilizatorul tocmai
     * a șters selecția din combobox) — o listă goală e răspunsul corect, nu un 404.
     */
    private function contactsForAccount(?string $accountId): AnonymousResourceCollection
    {
        $account = $accountId !== null && $accountId !== '' ? Account::query()->find($accountId) : null;

        if ($account === null) {
            return DealContactOptionResource::collection(collect());
        }

        return DealContactOptionResource::collection(
            $account->contacts()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
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
