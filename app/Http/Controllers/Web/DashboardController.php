<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityEntryResource;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\User;
use App\Models\Variant;
use App\Support\Stock\LowStockRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-DEMO-01 — dashboard-ul de start al unui workspace: 4 KPI tiles + feed de
 * activitate. Interogări simple de agregare, direct pe tabelele deja populate de seed
 * (§7.8) — scopate automat de `TenantScope` (plan §7.2): NICIUN `where tenant_id`
 * manual aici, RLS + global scope se ocupă de izolare.
 */
class DashboardController extends Controller
{
    /**
     * `/dashboard` fără workspace în cale (ADR-002) — redirect către primul workspace
     * al utilizatorului, ca în comutator (ordonat după numele tenantului,
     * `Membership::forCurrentUserAcrossTenants`).
     */
    public function redirectToDefaultWorkspace(Request $request): RedirectResponse
    {
        $userId = $request->user()->getAuthIdentifier();

        $membership = Membership::forCurrentUserAcrossTenants($userId)->first();

        // Un utilizator autentificat fără niciun membership activ nu are unde ajunge —
        // situație de configurare, nu de rutare greșită.
        // Mesajul ajunge pe pagina 403 temată (`resources/views/errors/403.blade.php`
        // randează `getMessage()`), deci trece prin catalog — audit 2026-09-23.
        abort_if($membership === null, 403, __('rules.members.no_workspace'));

        return redirect()->route('workspace.dashboard', ['workspace' => $membership->tenant->slug]);
    }

    public function show(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'kpis' => [
                'openPipelineValue' => (float) Deal::query()
                    ->where('status', Deal::STATUS_OPEN)
                    ->sum('value'),

                'ordersThisMonth' => (int) Order::query()
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),

                'overdueInvoices' => [
                    'count' => (int) Invoice::query()->where('status', Invoice::STATUS_OVERDUE)->count(),
                    // `balance_due`, nu `total`: e suma încă restantă, nu valoarea
                    // inițială a facturii — semnificația literală a „restante".
                    'amount' => (float) Invoice::query()->where('status', Invoice::STATUS_OVERDUE)->sum('balance_due'),
                ],

                // `LowStockRule`, nu o comparație proprie: regula are un prag PER VARIANTĂ
                // (`low_stock_threshold`), exclude variantele inactive și agregă `available`
                // pe toate locațiile. Placa număra până acum rânduri de `inventory_levels`
                // sub un prag fix de 5 — alt număr decât cel din „Products", pe același
                // workspace, iar lista de sub placă îl face verificabil.
                'lowStockAlerts' => (int) LowStockRule::lowVariants()->count(),
            ],

            // AMÂNATE (`Inertia::defer`, ca în AccountController/InvoiceController): shell-ul,
            // KPI-urile și feed-ul apar la prima cerere; agregările pentru grafice vin în a
            // doua, cu schelet în forma graficului. Dashboard-ul e prima pagină de după
            // login, deci ce se vede primul contează mai mult decât ce e complet.
            'charts' => Inertia::defer(fn () => $this->charts()),
            'attention' => Inertia::defer(fn () => $this->attention()),

            'activity' => $this->recentActivity($request->user()),
        ]);
    }

    /**
     * Feed-ul e o citire a jurnalului de activitate, deci urmează rândul lui din matricea §7.4:
     * Owner și Manager văd tot tenantul (`activity_log.view`), Agentul doar acțiunile proprii
     * (`activity_log.view_own`), Viewer-ul nimic. Cine n-are acces primește `null`, nu o listă
     * goală: „No recent activity yet" ar afirma ceva fals despre workspace.
     */
    /**
     * Seriile pentru graficele dashboard-ului: 12 luni de venit și de afaceri câștigate, plus
     * pipeline-ul pe etape deschise.
     *
     * Agregarea se face în SQL, nu în PHP: `deals` și `orders` au zeci de mii de rânduri în
     * setul demo, iar încărcarea lor ca modele doar ca să fie însumate ar fi cel mai scump
     * lucru de pe pagină. `TenantScope` + RLS se ocupă de izolare (plan §7.2) — niciun
     * `where tenant_id` manual.
     *
     * @return array{months: list<string>, orders: list<float>, ordersCount: list<int>, ordersMonthToDate: array{current: int, previous: int}, wonDeals: list<float>, ordersByStatus: array<string, int>, pipeline: list<array<string, mixed>>}
     */
    private function charts(): array
    {
        // `$since` se DERIVĂ din aceeași expresie care produce lista, nu se re-parsează din
        // eticheta ei. Varianta anterioară făcea `Carbon::createFromFormat('Y-m', '2025-11')`,
        // iar formatul fără zi lasă ziua de AZI: pe 31 octombrie ieșea „31 noiembrie 2025",
        // adică 1 decembrie, deci filtrul tăia prima lună a ferestrei și graficul arăta zero
        // pe ea. Tăcut, și doar în 7-8 zile pe an (31 ian/mar/mai/aug/oct, 29-31 ian) — exact
        // clasa de defect pentru care `ordersMonthToDate` de mai jos folosește
        // `subMonthNoOverflow()`. Pornind din ziua 1, nicio lună n-are cum să dea pe dinafară.
        $since = now()->startOfMonth()->subMonths(11);
        $months = collect(range(0, 11))->map(fn (int $ahead) => $since->copy()->addMonths($ahead)->format('Y-m'));

        // Doar comenzi CONFIRMATE sau mai departe: un draft și o comandă anulată nu sunt venit.
        $orders = Order::query()
            ->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PARTIALLY_FULFILLED, Order::STATUS_FULFILLED])
            ->where('placed_at', '>=', $since)
            ->selectRaw("to_char(date_trunc('month', placed_at), 'YYYY-MM') as month, sum(grand_total) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        // A DOUA interogare pe `orders`, nu `count(*)` lângă `sum()` în prima: numărătoarea
        // trebuie să aibă EXACT definiția KPI-ului de deasupra liniei — `created_at`, fără
        // filtru de status (vezi `show()`) — iar seria de bani are alta (`placed_at`, doar
        // comenzi confirmate). Împachetate într-o singură interogare, ultimul punct al liniei
        // ar fi contrazis cifra din propria placă. `DashboardTest` ține egalitatea asta.
        $ordersCount = Order::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("to_char(date_trunc('month', created_at), 'YYYY-MM') as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        // Variația lunară de pe placa de comenzi se calculează pe perioade COMPARABILE: luna
        // curentă până azi față de luna trecută până în aceeași zi. Altfel, pe 5 ale lunii,
        // cinci zile s-ar compara cu treizeci și una, iar placa ar afișa o prăbușire roșie în
        // prima săptămână a FIECĂREI luni — o cifră corect calculată și complet falsă ca
        // afirmație.
        //
        // `subMonthNoOverflow()`, nu `subMonth()`: pe 31 martie, al doilea dă 3 MARTIE (31
        // februarie nu există, deci Carbon dă pe dinafară în luna următoare) — adică ar
        // compara luna curentă cu primele trei zile ale ei înseși. Varianta „no overflow"
        // fixează ziua la ultima validă a lunii-țintă, 28 sau 29 februarie.
        $currentMonthStart = now()->startOfMonth();
        $samePointLastMonth = now()->subMonthNoOverflow();
        $monthToDate = Order::query()
            ->where('created_at', '>=', $samePointLastMonth->copy()->startOfMonth())
            ->selectRaw('count(*) filter (where created_at >= ?) as current', [$currentMonthStart])
            ->selectRaw('count(*) filter (where created_at < ?) as previous', [$samePointLastMonth])
            ->first();

        // Afacerile câștigate se numără pe luna în care au INTRAT în etapa de câștig
        // (evenimentul din `deal_stage_events`), nu pe `updated_at` — acela se mișcă la orice
        // editare ulterioară și ar muta retroactiv venitul dintr-o lună în alta.
        $won = DealStageEvent::query()
            ->join('stages', 'stages.id', '=', 'deal_stage_events.to_stage_id')
            ->join('deals', 'deals.id', '=', 'deal_stage_events.deal_id')
            ->where('stages.is_won', true)
            ->where('deal_stage_events.changed_at', '>=', $since)
            ->selectRaw("to_char(date_trunc('month', deal_stage_events.changed_at), 'YYYY-MM') as month, sum(deals.value) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        // Distribuția pe status e un instantaneu al registrului ÎNTREG, nu al ultimelor 12
        // luni: donutul răspunde la „ce am pe masă acum", iar o comandă din urmă cu doi ani
        // încă nelivrată e tocmai ce trebuie să se vadă.
        $ordersByStatus = Order::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (int|string $total) => (int) $total)
            ->all();

        $pipeline = Pipeline::query()->where('is_default', true)->first()
            ?? Pipeline::query()->oldest('created_at')->oldest('id')->first();

        $byStage = Deal::query()
            ->where('status', Deal::STATUS_OPEN)
            ->selectRaw('stage_id, count(*) as deals, coalesce(sum(value), 0) as value')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        // Doar etapele DESCHISE: „Won" și „Lost" sunt terminale, iar includerea lor ar face ca
        // un pipeline sănătos să arate ca unul blocat la capăt.
        $stages = Stage::query()
            ->where('pipeline_id', $pipeline?->getKey())
            ->where('is_won', false)
            ->where('is_lost', false)
            ->orderBy('position')
            ->get()
            ->map(fn (Stage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'probability' => (int) $stage->probability,
                'deals' => (int) ($byStage->get($stage->id)?->deals ?? 0),
                'value' => (float) ($byStage->get($stage->id)?->value ?? 0),
            ])
            ->values()
            ->all();

        return [
            'months' => $months->all(),
            'orders' => $months->map(fn (string $month) => (float) ($orders[$month] ?? 0))->all(),
            'ordersCount' => $months->map(fn (string $month) => (int) ($ordersCount[$month] ?? 0))->all(),
            'ordersMonthToDate' => [
                'current' => (int) ($monthToDate->current ?? 0),
                'previous' => (int) ($monthToDate->previous ?? 0),
            ],
            'wonDeals' => $months->map(fn (string $month) => (float) ($won[$month] ?? 0))->all(),
            'ordersByStatus' => $ordersByStatus,
            'pipeline' => $stages,
        ];
    }

    /**
     * „Ce cere acțiune acum" — cele trei liste din spatele KPI-urilor. Un KPI spune CÂTE;
     * lista spune CARE, cu link, ca numărul să devină acționabil în loc să rămână decor.
     *
     * @return array{overdueInvoices: list<array<string, mixed>>, closingSoon: list<array<string, mixed>>, lowStock: list<array<string, mixed>>}
     */
    private function attention(): array
    {
        $today = today();
        // `absolute: false`: căile ajung în `<Link href>` pe client, unde o cale relativă e
        // exact ce așteaptă Inertia — la fel ca `${base}/deals/...` scris de mână în pagini.
        // Un URL absolut ar lega linkul de host-ul cererii care a generat răspunsul.

        return [
            'overdueInvoices' => Invoice::query()
                ->where('status', Invoice::STATUS_OVERDUE)
                ->with('order.account:id,name')
                ->orderByDesc('balance_due')
                ->limit(4)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'label' => $invoice->invoice_number,
                    'account' => $invoice->order?->account?->name,
                    'amount' => (float) $invoice->balance_due,
                    'daysOverdue' => (int) ($invoice->due_date?->diffInDays($today) ?? 0),
                    'url' => route('invoices.show', $invoice, absolute: false),
                ])->all(),

            'closingSoon' => Deal::query()
                ->where('status', Deal::STATUS_OPEN)
                ->whereBetween('expected_close_date', [$today, $today->copy()->addDays(14)])
                ->with('account:id,name')
                ->orderBy('expected_close_date')
                ->limit(4)
                ->get()
                ->map(fn (Deal $deal) => [
                    'id' => $deal->id,
                    'label' => $deal->title,
                    'account' => $deal->account?->name,
                    'amount' => $deal->value !== null ? (float) $deal->value : null,
                    'closesOn' => $deal->expected_close_date?->toDateString(),
                    'url' => route('deals.show', $deal, absolute: false),
                ])->all(),

            // Aceeași regulă ca placa de deasupra (`LowStockRule`), deci lista nu poate
            // contrazice numărul. `available` vine deja calculat de ea, agregat pe toate
            // locațiile, iar ordonarea e cea mai urgentă întâi.
            'lowStock' => LowStockRule::lowVariants()
                ->with('product:id,name')
                ->limit(4)
                ->get()
                ->map(fn (Variant $variant) => [
                    'id' => $variant->id,
                    'label' => $variant->sku,
                    'product' => $variant->product?->name,
                    'available' => (int) $variant->available,
                    'threshold' => (int) $variant->low_stock_threshold,
                    'url' => route('stock.show', $variant, absolute: false),
                ])->all(),
        ];
    }

    private function recentActivity(User $user): ?AnonymousResourceCollection
    {
        $seesWholeTenant = $user->can('activity_log.view');

        if (! $seesWholeTenant && ! $user->can('activity_log.view_own')) {
            return null;
        }

        return ActivityEntryResource::collection(
            ActivityLog::query()
                ->when(! $seesWholeTenant, fn (Builder $query) => $query->where('user_id', $user->getKey()))
                // `auditable` eager, nu doar `user`: `ActivityEntryResource::subjectName()`
                // citește numele înregistrării atinse, iar proiectul interzice lazy loading
                // (`Model::preventLazyLoading`). MorphTo se încarcă GRUPAT pe tip, deci cel
                // mult o interogare per tip de entitate pentru cele 10 rânduri, nu 10.
                ->with(['user', 'auditable'])
                // Departajare pe `id`, nu doar `created_at` (Faza 4, prins de un test care
                // a devenit instabil când suita a crescut): două acțiuni din aceeași secundă
                // — de pildă dezactivarea unui membru și scrierea care o urmează — ieșeau în
                // ordine arbitrară, dictată de planul de execuție. `id` e ULID, deci
                // lexicografic în ordinea timpului: departajează corect, nu doar stabil.
                ->latest('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
        );
    }
}
