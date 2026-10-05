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
use App\Models\Scopes\NotAnonymizedContactScope;
use App\Models\Stage;
use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Bulk\BulkMatchingRowCount;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Lists\DealList;
use App\Support\Permissions;
use App\Support\RecentlyViewed;
use App\Support\SavedViews\ListColumns;
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
            // Pachetul C („bulk") — §13.1, linkul „Select all N deals matching this filter"
            // are nevoie de N-ul EXACT pe care ÎL ATINGE OPERAȚIA, nu al filtrului brut —
            // vezi P2-003 (code review) și docblock-ul echivalent din
            // `AccountController::index()`. Deferred separat de `deals`: nu blochează
            // randarea rândurilor, e doar un COUNT pe același filtru.
            'total' => Inertia::defer(fn () => BulkMatchingRowCount::for(
                $user,
                BulkWritableResources::resolve('deals'),
                $list->query($query, $user),
            )),
            'filters' => $query->toArray(),
            // Selector de coloane (specs.md §15.1) — validate server-side ca orice filtru;
            // un `?columns=` necunoscut/gol cade pe `SavedViewResourceType::defaultColumns()`.
            'columns' => ListColumns::fromRequest($request, 'deals'),
            'can' => [
                'create' => Gate::allows('create', Deal::class),
                // Separat de export (§13.5, §7.4 nota ³): Agent nu are `deals.change_owner`
                // în catalog (`Permissions::forRoles()`), deci `bulkWrite` e `false` pentru
                // el aici — nesimetric față de Accounts, decizie deja existentă în RBAC.
                'bulkWrite' => Gate::allows('bulkReassignOwner', Deal::class),
            ],
            // Gol când `can.bulkWrite` e fals — ca la `create()`/`edit()` mai jos, nu o
            // listă calculată degeaba pentru un rol care n-o poate folosi.
            'owners' => Gate::allows('bulkReassignOwner', Deal::class) ? $this->ownerOptions() : [],
            'bulkConfirmationThreshold' => BulkConfirmationThreshold::for($user),
            'bulkRowCap' => BulkConfirmationThreshold::rowCapForRole($user),
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
     *
     * Code review P3 — `is_string($raw)`, tiparul din `AccountLookupController::__invoke()`:
     * `?account[]=x` ajunge ca array la `$request->query('account')`, iar un array trimis
     * la `contactsForAccount()`/`findOrFail()` fie ar arunca `TypeError` (500), fie ar
     * face o interogare `whereIn` neintenționată. Un query param cu forma greșită devine
     * „niciun cont", nu o eroare de server.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Deal::class);

        $raw = $request->query('account');
        $accountId = is_string($raw) ? $raw : null;
        $account = $accountId !== null && $accountId !== '' ? Account::query()->findOrFail($accountId) : null;

        return Inertia::render('Deals/Create', [
            'account' => $account !== null ? ['id' => $account->id, 'name' => $account->name] : null,
            'contacts' => $this->contactsForAccount($account),
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

        return redirect()->route('deals.show', $deal)->with('success', __('flash.deals.created'));
    }

    public function show(Request $request, Deal $deal): Response
    {
        Gate::authorize('view', $deal);

        RecentlyViewed::record($request, 'deal', $deal->getKey(), $deal->title, route('deals.show', $deal));

        $deal->load([
            'account:id,name',
            // §20.5 — contactul principal anonimizat rămâne vizibil AICI (text neutru, fără
            // link, `Deals/Show.tsx`), spre deosebire de restul aplicației: bypass explicit al
            // `NotAnonymizedContactScope`, altfel un deal cu istoric ar arăta „—", identic cu
            // „n-a avut niciodată contact principal", ceea ce pierde informația.
            'primaryContact' => fn ($query) => $query
                ->withoutGlobalScope(NotAnonymizedContactScope::class)
                ->select(['id', 'first_name', 'last_name', 'anonymized_at']),
            'pipeline:id,name',
            'stage:id,name,is_won,is_lost',
            'owner:id,name',
        ]);

        // `orderByDesc('id')` ca departajare: `changed_at` e `timestamp(0)` (precizia
        // implicită Laravel pe Postgres — vezi migrația `create_deal_stage_events_table`),
        // deci trunchiată la secundă întreagă. Două mutări din ACELAȘI deal, în aceeași
        // secundă (un utilizator rapid, sau un test E2E), au `changed_at` IDENTIC — fără
        // departajare, `ORDER BY changed_at DESC` nu garantează ordinea de inserare pentru
        // acele rânduri (găsit prin E2E, `e2e/specs/deals-pipeline.spec.ts`: 3 mutări
        // succesive în aceeași secundă ieșeau într-o ordine nedeterministă pe „Stage
        // history"). ULID-urile cresc monoton la inserare (același tipar ca `board()`,
        // mai jos: `orderByDesc('created_at')->orderByDesc('id')`).
        $events = $deal->stageEvents()
            ->with(['fromStage:id,name', 'toStage:id,name', 'changedBy:id,name'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
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
     *
     * Code review P2-001/P2-002 — `account` iese explicit în props, REZOLVAT din exact
     * aceeași variabilă `$account` care alimentează `contacts`: o singură sursă de adevăr
     * pentru „ce cont e activ acum în formular", nu două (`deal.account` pe client +
     * `contacts` pe server, care puteau diverge la refresh cu `?account=` rămas în URL,
     * sau la „Clear" — vezi P2-001, `Edit.tsx` trimite acum `account=''` explicit, nu
     * omite cheia, ca `$request->has('account')` să distingă „gol, ales explicit" de
     * „absent, prima încărcare").
     *
     * `?account=` gol → „niciun cont" (contacts goale), NU fallback pe contul deal-ului —
     * altfel „Clear" în combobox n-ar goli niciodată lista de contacte (P2-001).
     * `?account=<id>` invalid/din alt tenant → `findOrFail` → 404, simetric cu `create()`.
     * `?account[]=x` (P3) → `is_string($raw)` îl tratează ca absent, nu ca 500.
     */
    public function edit(Request $request, Deal $deal): Response
    {
        Gate::authorize('update', $deal);

        // §20.5 — același bypass ca `show()`: fără el, un contact principal anonimizat
        // NU se încarcă deloc (`null`), formularul pornește cu `primary_contact_id` GOL, iar
        // salvarea de rutină (ex. doar „Value") ar goli tăcut `deals.primary_contact_id` —
        // exact integritatea referențială pe care anonimizarea trebuie s-o păstreze. Contactul
        // rămâne exclus din `contacts` (opțiunile din dropdown, `contactsForAccount()`);
        // `Deals/Edit.tsx` îi arată o opțiune informativă separată, pe baza `isAnonymized`.
        $deal->load([
            'account:id,name',
            'primaryContact' => fn ($query) => $query
                ->withoutGlobalScope(NotAnonymizedContactScope::class)
                ->select(['id', 'first_name', 'last_name', 'anonymized_at']),
        ]);

        $raw = $request->query('account');
        $accountId = is_string($raw) ? $raw : null;

        if ($request->has('account')) {
            $account = $accountId !== null && $accountId !== '' ? Account::query()->findOrFail($accountId) : null;
        } else {
            $account = $deal->account;
        }

        return Inertia::render('Deals/Edit', [
            'deal' => DealResource::make($deal),
            'account' => $account !== null ? ['id' => $account->id, 'name' => $account->name] : null,
            'contacts' => $this->contactsForAccount($account),
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

        return redirect()->route('deals.show', $deal)->with('success', __('flash.deals.updated'));
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        Gate::authorize('delete', $deal);

        $deal->delete();

        return redirect()->route('deals.index')->with('success', __('flash.deals.deleted'));
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

        // Tiebreaker pe `id` (DOM-05, audit 2026-09-23) — `.ai/rules/tenancy.md`:
        // `created_at` are precizie 0, deci „cel mai vechi" nu e determinist singur.
        $pipeline = Pipeline::query()->where('is_default', true)->first()
            ?? Pipeline::query()->oldest('created_at')->oldest('id')->first();

        abort_if($pipeline === null, 404);

        $stages = Stage::query()->where('pipeline_id', $pipeline->getKey())->orderBy('position')->get();
        $stageIds = $stages->pluck('id');

        // 1 interogare pentru TOATE totalurile (nu una per coloană). Suma de valoare intră ca
        // A DOUA AGREGARE pe același `GROUP BY`, nu ca interogare separată: are exact aceleași
        // filtre, deci o a doua le-ar putea desincroniza tăcut la prima modificare a uneia.
        //
        // Suma e pe TOATĂ etapa, iar cardurile trimise sunt plafonate la 50 (`stage_rank`) —
        // deci antetul nu minte niciodată despre ce nu se vede, iar `hasMore` spune că există.
        $totals = Deal::query()
            ->whereIn('stage_id', $stageIds)
            ->when($ownerFilter === 'me', fn ($query) => $query->where('owner_user_id', $user->getKey()))
            ->selectRaw('stage_id, count(*) as aggregate, coalesce(sum(value), 0) as value_sum')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

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
            $row = $totals->get($stage->getKey());
            $total = (int) ($row?->aggregate ?? 0);

            return [
                'stage' => DealStageResource::make($stage),
                'deals' => DealSummaryResource::collection($stageDeals),
                'total' => $total,
                // Afacerile fără valoare contează la `total`, dar adaugă zero aici
                // (`coalesce`) — un pipeline cu zece oportunități neevaluate arată zece
                // carduri și o sumă mică, ceea ce e adevărat.
                'valueTotal' => (float) ($row?->value_sum ?? 0),
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
     * Contactele disponibile pentru „Primary contact", scopate la contul REZOLVAT în
     * `create()`/`edit()` (§9 task: „contactul principal depinde de cont"). Primește
     * modelul deja rezolvat de apelant (nu un id) — o singură interogare de cont per
     * cerere, nu una aici plus alta pentru propul `account` (P2-002).
     */
    private function contactsForAccount(?Account $account): AnonymousResourceCollection
    {
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
