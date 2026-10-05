<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\InventoryLevel;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Product;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FR-DEMO-01 + FR-TEN-01, verificate prin cerere HTTP reală — adică prin tot lanțul care
 * contează: `auth → SetSessionContext → ResolveWorkspace`, global scope, RLS, roluri per
 * tenant, props Inertia.
 *
 * Deliberat NU folosește factories: la momentul scrierii, seed-ul de volum era construit în
 * paralel, iar un test de shell care depinde de datele de demo ar fi început să pice din
 * motive care n-au nimic de-a face cu shell-ul.
 */
class DashboardTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        $this->seedBusinessData($this->marlin, dealValue: 12_500.75, overdueBalance: 3_410.20);
        $this->seedBusinessData($this->cascade, dealValue: 999_999.99, overdueBalance: 888_888.88);

        $this->clearDatabaseTenantContext();
    }

    protected function tearDown(): void
    {
        // Altfel ceasul fixat de testul de mai jos scurge în orice test care rulează după el
        // în ACELAȘI proces — iar simptomul ar apărea la alt fișier.
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_dashboard_shows_kpis_computed_from_the_current_workspace_only(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/dashboard');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('kpis.openPipelineValue', 12500.75)
            ->where('kpis.ordersThisMonth', 1)
            ->where('kpis.overdueInvoices.count', 1)
            ->where('kpis.overdueInvoices.amount', 3410.2)
            ->has('activity')
            ->where('workspace.name', 'Marlin Fasteners & Supply Co.')
        );

        // Aceleași KPI-uri, alt workspace, aceeași sesiune: dacă vreo cifră ar fi
        // moștenită, s-ar vedea aici. Cifrele celui de-al doilea tenant sunt deliberat
        // absurd de mari, ca o scurgere să fie imposibil de confundat cu o coincidență.
        $this->actingAs($this->owner)->get('/cascade/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.openPipelineValue', 999999.99)
                ->where('kpis.overdueInvoices.amount', 888888.88)
                ->where('workspace.name', 'Cascade Hydraulic Components')
            );
    }

    /**
     * §7.4, rândul „Jurnal de activitate": Owner și Manager citesc tot tenantul, Agentul doar
     * acțiunile proprii, Viewer-ul nimic. Feed-ul de pe dashboard e o citire a acelui jurnal,
     * deci urmează același rând.
     */
    public function test_the_activity_feed_follows_the_activity_log_row_of_the_permission_matrix(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function () use ($agent): void {
            $this->logActivity($this->owner, 'login');
            $this->logActivity($agent, 'exported', Account::class);
        });
        $this->clearDatabaseTenantContext();

        foreach ([$this->owner, $manager] as $user) {
            $this->actingAs($user)->get('/marlin/dashboard')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->has('activity', 2));
        }

        $this->actingAs($agent)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 1)
                ->where('activity.0.actor', $agent->name)
                ->where('activity.0.description', 'Exported Account')
                // Valoarea BRUTĂ a enum-ului, pe lângă fraza compusă: feed-ul o folosește
                // pentru semnalul de culoare (`lib/activityTone`). Se verifică aici fiindcă
                // regula din `types/generated.d.ts` cere ca orice schimbare de formă a unui
                // Resource să fie prinsă ȘI de un test de contract, nu doar oglindită în tip.
                ->where('activity.0.action', 'exported')
                // `kind` — CE s-a întâmplat, derivat din `action` + `auditable_type` +
                // `new_values` (`App\Support\Activity\ActivityKind`). Pentru un verb
                // neambiguu ca `exported` e identic cu `action`; diferă doar la `updated`,
                // unde acoperea deopotrivă o mutare de etapă, o factură plătită și o
                // editare oarecare — vezi testul dedicat de mai jos.
                ->where('activity.0.kind', 'exported')
                // Numele propriu al înregistrării: `null` aici fiindcă rândul de test n-are
                // `auditable_id`, deci nu exista entitate de citit.
                ->where('activity.0.subjectName', null)
            );

        // `null`, nu listă goală: „No recent activity yet" ar afirma ceva fals despre workspace.
        $this->actingAs($viewer)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activity', null));
    }

    /**
     * Graficele și lista de urgențe sunt AMÂNATE (`Inertia::defer`): absența lor din primul
     * răspuns nu e un detaliu de implementare, e contractul pe care se sprijină scheletele din
     * pagină. Dacă într-o zi ar ajunge sincrone, aserțiunea de mai jos pică — ȘI trebuie să
     * pice, fiindcă atunci prima vopsea a dashboard-ului ar aștepta șase agregări.
     */
    public function test_the_charts_and_the_attention_lists_are_deferred_out_of_the_first_response(): void
    {
        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('kpis')
                ->has('activity')
                ->missing('charts')
                ->missing('attention')
            );
    }

    public function test_the_deferred_charts_aggregate_the_current_workspace_only(): void
    {
        $thisMonth = now()->format('Y-m');

        $this->partialReload('marlin', 'charts')
            ->assertOk()
            // 12 luni, în ordine cronologică, ultima fiind luna curentă — forma pe care
            // `AreaChart` o presupune fără s-o verifice.
            ->assertJsonCount(12, 'props.charts.months')
            ->assertJsonPath('props.charts.months.11', $thisMonth)
            ->assertJsonCount(12, 'props.charts.orders')
            ->assertJsonCount(12, 'props.charts.ordersCount')
            ->assertJsonCount(12, 'props.charts.wonDeals')
            // Comanda confirmată, pe luna lui `placed_at`.
            ->assertJsonPath('props.charts.orders.11', 4217.44)
            // Afacerea câștigată, pe luna EVENIMENTULUI de etapă. `7000`, nu `7000.0`:
            // `json_encode` scrie un float fără parte fracționară ca întreg (n-avem
            // `JSON_PRESERVE_ZERO_FRACTION`), iar `assertJsonPath` compară strict.
            ->assertJsonPath('props.charts.wonDeals.11', 7000)
            ->assertJsonPath('props.charts.ordersByStatus.confirmed', 1)
            // Perioadele comparabile ale plăcii: comanda din seed e de azi, deci intră în
            // „current"; luna trecută n-are niciuna.
            ->assertJsonPath('props.charts.ordersMonthToDate.current', 1)
            ->assertJsonPath('props.charts.ordersMonthToDate.previous', 0)
            // O singură bară: etapa „Won" e terminală și e exclusă deliberat.
            ->assertJsonCount(1, 'props.charts.pipeline')
            ->assertJsonPath('props.charts.pipeline.0.name', 'Qualification')
            ->assertJsonPath('props.charts.pipeline.0.probability', 25)
            ->assertJsonPath('props.charts.pipeline.0.deals', 1)
            ->assertJsonPath('props.charts.pipeline.0.value', 12500.75);

        // Aceeași sesiune, alt workspace: cifrele absurd de mari ale celui de-al doilea tenant
        // ar fi imposibil de confundat cu o coincidență dacă ar apărea aici.
        $this->partialReload('cascade', 'charts')
            ->assertOk()
            ->assertJsonPath('props.charts.pipeline.0.value', 999999.99);
    }

    /**
     * Invariantul pe care îl presupune linia de pe placa „Orders this month": ultimul punct al
     * seriei ESTE cifra din placă. Seria trebuie deci numărată cu definiția KPI-ului
     * (`created_at`, fără filtru de status), nu cu cea a seriei de bani (`placed_at`, doar
     * comenzi confirmate) — două interogări separate în controller, exact din motivul ăsta.
     *
     * Testul e construit ca să PICE dacă cineva le unifică pentru eficiență: comanda de mai
     * jos e un `draft` fără `placed_at`, deci intră în numărătoare și NU în bani.
     */
    public function test_the_order_count_series_ends_on_the_same_number_the_kpi_tile_shows(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = Account::query()->firstOrFail();

            $draft = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_DRAFT,
                'grand_total' => 1_000.00,
            ]);
            $draft->created_by = $this->owner->getKey();
            $draft->save();
        });
        $this->clearDatabaseTenantContext();

        $kpi = $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertOk()
            ->viewData('page')['props']['kpis']['ordersThisMonth'];

        $this->assertSame(2, $kpi, 'draft-ul trebuie să intre în KPI — altfel premisa testului s-a schimbat');

        $this->partialReload('marlin', 'charts')
            ->assertOk()
            ->assertJsonPath('props.charts.ordersCount.11', $kpi)
            // …iar banii NU s-au mișcat: draft-ul nu e venit.
            ->assertJsonPath('props.charts.orders.11', 4217.44);
    }

    public function test_the_attention_lists_name_the_records_behind_the_kpi_numbers(): void
    {
        $this->partialReload('marlin', 'attention')
            ->assertOk()
            ->assertJsonCount(1, 'props.attention.overdueInvoices')
            ->assertJsonPath('props.attention.overdueInvoices.0.account', 'Northwind Industrial Supply LLC')
            ->assertJsonPath('props.attention.overdueInvoices.0.amount', 3410.2)
            // `due_date` e cast la `date`, deci diferența e în zile calendaristice întregi.
            ->assertJsonPath('props.attention.overdueInvoices.0.daysOverdue', 14)
            // Cale RELATIVĂ, nu URL absolut — vezi nota din `attention()`.
            ->assertJsonPath('props.attention.overdueInvoices.0.url', fn (?string $url) => is_string($url) && str_starts_with($url, '/marlin/invoices/'))

            // Afacerea cu termen în 3 zile — doar cea deschisă, cea câștigată n-are termen.
            ->assertJsonCount(1, 'props.attention.closingSoon')
            ->assertJsonPath('props.attention.closingSoon.0.label', 'Annual fastener supply agreement')
            ->assertJsonPath('props.attention.closingSoon.0.closesOn', now()->addDays(3)->toDateString())

            // 6 pe stoc − 4 rezervate = 2 disponibile, sub pragul de 10 al variantei. Numele
            // produsului vine prin relația `product`, deci rândul e și garda pentru eager
            // loading (proiectul interzice lazy loading).
            ->assertJsonCount(1, 'props.attention.lowStock')
            ->assertJsonPath('props.attention.lowStock.0.label', 'HEX-M8-50')
            ->assertJsonPath('props.attention.lowStock.0.product', 'Hex bolt M8')
            ->assertJsonPath('props.attention.lowStock.0.available', 2)
            ->assertJsonPath('props.attention.lowStock.0.threshold', 10);
    }

    /**
     * Un reload parțial pe un singur prop amânat. `assertInertia()` nu e folosibil aici: pe o
     * cerere parțială Inertia răspunde JSON brut, nu view-ul Blade cu `page`, iar
     * `AssertableInertia::fromTestResponse()` caută `assertViewHas('page')` — aceeași notă ca
     * în `tests/Feature/Products/ProductLowStockCountTest.php`.
     *
     * Versiunea se citește dintr-un prim răspuns, nu se inventează: una greșită întoarce 409
     * (cerere de reîncărcare completă), deci testul ar măsura altceva.
     */
    private function partialReload(string $workspace, string $prop): TestResponse
    {
        return $this->partialReloadAs($this->owner, $workspace, $prop);
    }

    private function partialReloadAs(User $user, string $workspace, string $prop): TestResponse
    {
        $version = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => $prop,
            ])
            ->get("/{$workspace}/dashboard")
            ->headers->get('x-inertia-version');

        return $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => $prop,
            ])
            ->get("/{$workspace}/dashboard");
    }

    /**
     * Placa „Low stock alerts" și lista de sub ea trebuie să numere ACELAȘI lucru.
     *
     * Dashboard-ul avea până acum o definiție proprie — `inventory_levels` cu
     * `(on_hand - reserved) <= 5` — care diferea de `LowStockRule` pe patru axe: prag fix în
     * loc de cel al variantei, pe rând de LOCAȚIE (o variantă ținută în două depozite se
     * număra de două ori), fără condiția `is_active`, și `<=` în loc de `<`. Fixtura de mai
     * jos e construită ca fiecare dintre cele patru să producă un număr diferit dacă regula
     * se desparte iar.
     */
    public function test_the_low_stock_tile_and_list_count_the_same_thing(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $product = Product::query()->firstOrFail();
            $second = Location::query()->create(['name' => 'Overflow yard']);

            // (a) Varianta din seed e în DOUĂ locații: `available` se agregă (2 + 1 = 3 < 10),
            //     deci rămâne UN singur rând de alertă, nu două.
            InventoryLevel::query()->create([
                'variant_id' => Variant::query()->where('sku', 'HEX-M8-50')->value('id'),
                'location_id' => $second->getKey(),
                'on_hand' => 1,
                'reserved' => 0,
            ]);

            // (b) Prag NUL — stoc 0, dar varianta nu cere reaprovizionare. Regula veche ar fi
            //     numărat-o (0 <= 5).
            $untracked = Variant::query()->create(['product_id' => $product->getKey(), 'sku' => 'NO-THRESHOLD', 'price' => 1, 'cost' => 1]);
            InventoryLevel::query()->create(['variant_id' => $untracked->getKey(), 'location_id' => $second->getKey(), 'on_hand' => 0, 'reserved' => 0]);

            // (c) Variantă INACTIVĂ sub prag: scoasă din catalog, deci nu e un semnal.
            $inactive = Variant::query()->create(['product_id' => $product->getKey(), 'sku' => 'RETIRED', 'price' => 1, 'cost' => 1, 'low_stock_threshold' => 8, 'is_active' => false]);
            InventoryLevel::query()->create(['variant_id' => $inactive->getKey(), 'location_id' => $second->getKey(), 'on_hand' => 1, 'reserved' => 0]);

            // (d) Exact PE prag: `<`, nu `<=` — 5 din 5 nu e încă sub prag.
            $atThreshold = Variant::query()->create(['product_id' => $product->getKey(), 'sku' => 'AT-THRESHOLD', 'price' => 1, 'cost' => 1, 'low_stock_threshold' => 5]);
            InventoryLevel::query()->create(['variant_id' => $atThreshold->getKey(), 'location_id' => $second->getKey(), 'on_hand' => 5, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        $kpi = $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertOk()
            ->viewData('page')['props']['kpis']['lowStockAlerts'];

        $this->assertSame(1, $kpi, 'doar HEX-M8-50 e sub pragul propriu — vezi cele patru capcane din fixtură');

        $this->partialReload('marlin', 'attention')
            ->assertOk()
            ->assertJsonCount($kpi, 'props.attention.lowStock')
            ->assertJsonPath('props.attention.lowStock.0.label', 'HEX-M8-50')
            // Agregat pe AMBELE locații: 2 + 1.
            ->assertJsonPath('props.attention.lowStock.0.available', 3);
    }

    /**
     * Fereastra de 12 luni trebuie să înceapă exact la prima lună a seriei, inclusiv în zilele
     * în care ziua curentă NU EXISTĂ în luna-țintă.
     *
     * Varianta inițială re-parsa eticheta lunii (`Carbon::createFromFormat('Y-m', '2025-11')`),
     * iar un format fără zi lasă ziua de AZI: pe 31 octombrie rezulta „31 noiembrie", adică
     * 1 decembrie, deci filtrul `where placed_at >= $since` tăia prima lună a propriei
     * ferestre. Graficul arăta zero pe ea — tăcut, și doar 7-8 zile pe an.
     *
     * 31 OCTOMBRIE e ales deliberat: luna de la care pleacă fereastra e noiembrie, cu 30 de
     * zile. Pe 5 octombrie testul trece și cu codul defect.
     */
    public function test_the_twelve_month_window_starts_correctly_on_a_day_that_the_target_month_lacks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00'));

        $firstMonth = now()->startOfMonth()->subMonths(11);

        TenantContext::run($this->marlin, function () use ($firstMonth): void {
            $order = new Order([
                'account_id' => Account::query()->value('id'),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_CONFIRMED,
                'grand_total' => 1_111.11,
                // În PRIMA lună a ferestrei — singurul rând pe care o margine greșită îl pierde.
                'placed_at' => $firstMonth->copy()->addDays(9),
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();
        });
        $this->clearDatabaseTenantContext();

        $this->partialReload('marlin', 'charts')
            ->assertOk()
            ->assertJsonPath('props.charts.months.0', $firstMonth->format('Y-m'))
            ->assertJsonPath('props.charts.months.11', now()->format('Y-m'))
            ->assertJsonCount(12, 'props.charts.months')
            // Rândul din prima lună e NUMĂRAT, nu tăiat de o margine mutată cu o lună.
            ->assertJsonPath('props.charts.orders.0', 1111.11);
    }

    /**
     * §7.4, litera „R*": îngustarea Agentului pe facturi e NECONDIȚIONATĂ — fără comutator
     * „ale mele / toate", spre deosebire de afaceri. Lista „Needs attention" era singurul loc
     * din aplicație care îi arăta numărul, clientul și suma unei facturi a altcuiva; linkul
     * ducea apoi la 403, deci informația era și scursă, și inutilă.
     *
     * Testul verifică ȘI partea cealaltă: afacerile NU se îngustează (`DealPolicy::view` nu
     * restrânge nimic, iar kanbanul îi dă Agentului „All deals"), ca o „simetrizare" bine
     * intenționată să pice aici.
     */
    public function test_an_agent_only_sees_overdue_invoices_from_their_own_orders(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($agent): void {
            $account = Account::query()->firstOrFail();

            // Comandă A AGENTULUI, cu factura ei restantă.
            $own = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $agent->getKey(),
                'status' => Order::STATUS_CONFIRMED,
                'grand_total' => 500.00,
            ]);
            $own->created_by = $agent->getKey();
            $own->save();

            $invoice = new Invoice(['status' => Invoice::STATUS_OVERDUE, 'total' => 500, 'balance_due' => 500, 'due_date' => now()->subDays(3)]);
            $invoice->order_id = $own->getKey();
            $invoice->save();
        });
        $this->clearDatabaseTenantContext();

        // Owner-ul vede ambele facturi restante: a lui (din seed) și a agentului.
        $this->partialReloadAs($this->owner, 'marlin', 'attention')
            ->assertOk()
            ->assertJsonCount(2, 'props.attention.overdueInvoices');

        // Agentul, doar pe a lui — restanța de 3.410,20 din seed aparține comenzii owner-ului.
        $this->partialReloadAs($agent, 'marlin', 'attention')
            ->assertOk()
            ->assertJsonCount(1, 'props.attention.overdueInvoices')
            ->assertJsonPath('props.attention.overdueInvoices.0.amount', 500)
            // …dar afacerile NU se îngustează: afacerea cu termen apropiat e a owner-ului și
            // Agentul o vede, fiindcă asta spune matricea.
            ->assertJsonCount(1, 'props.attention.closingSoon');
    }

    /**
     * Seria „won deals" numără AFACERI, nu evenimente de etapă. Trei moduri distincte de a
     * umfla cifra, toate reale în produs:
     *
     *  1. o afacere care iese din Won și reintră scrie DOUĂ evenimente — `MoveDealStageAction`
     *     nu interzice ieșirea, iar meniul „Move to stage…" oferă orice etapă;
     *  2. o afacere mutată înapoi în deschis rămâne cu evenimentul ei de câștig în jurnal;
     *  3. o afacere ȘTEARSĂ (soft delete) dispare din liste, dar un `join` brut pe `deals` o
     *     păstrează în agregare.
     *
     * Fixtura le pune pe toate trei, deci o revenire la numărarea evenimentelor pică aici.
     */
    public function test_won_deals_are_counted_once_each_and_only_while_still_won(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = Account::query()->firstOrFail();
            $open = Stage::query()->where('name', 'Qualification')->firstOrFail();
            $won = Stage::query()->where('name', 'Won')->firstOrFail();

            // (1) Câștigată, întoarsă în deschis, câștigată iar: DOUĂ intrări în Won, o singură
            //     afacere de 4.000. Contează ultima intrare.
            // Momentele sunt ancorate de LUNĂ, nu de „acum minus N zile": `subDays(5)` cade în
            // luna precedentă dacă testul rulează pe 5 ale lunii, iar aserțiunea de pe
            // `wonDeals.11` ar pica o dată la câteva zile pe lună, din motive de calendar.
            $twoMonthsAgo = now()->startOfMonth()->subMonths(2)->addDays(3);

            $yoyo = $this->dealInStage($account, $won, 'Re-won deal', 4_000.00, Deal::STATUS_WON);
            $this->stageEvent($yoyo, $open, $won, $twoMonthsAgo);
            $this->stageEvent($yoyo, $open, $won, now());

            // (2) Câștigată cândva, acum iar deschisă — nu mai e venit.
            $reopened = $this->dealInStage($account, $open, 'Reopened deal', 50_000.00, Deal::STATUS_OPEN);
            $this->stageEvent($reopened, $open, $won, now());

            // (3) Câștigată și ȘTEARSĂ.
            $deleted = $this->dealInStage($account, $won, 'Deleted deal', 90_000.00, Deal::STATUS_WON);
            $this->stageEvent($deleted, $open, $won, now());
            $deleted->delete();
        });
        $this->clearDatabaseTenantContext();

        $this->partialReload('marlin', 'charts')
            ->assertOk()
            // Luna curentă: 7.000 din seed (`Pilot order`) + 4.000 ai afacerii reintrate.
            // NU 8.000 (dublă numărare), NU 58.000 (cea redeschisă), NU 98.000 (cea ștearsă).
            ->assertJsonPath('props.charts.wonDeals.11', 11000)
            // …iar PRIMA intrare în câștig a afacerii reintrate nu lasă nimic în urmă: se
            // numără ultima, nu fiecare.
            ->assertJsonPath('props.charts.wonDeals.9', 0);
    }

    /**
     * Cele două serii de pe grafic citesc COLOANE DIFERITE, deliberat: banii se numără pe
     * `placed_at` (când a fost plasată comanda), numărul pe `created_at` (definiția plăcii de
     * deasupra). În fixtura comună cele două sunt egale, deci o serie calculată pe coloana
     * greșită ar da exact aceleași cifre — testul de mai jos le DESPARTE.
     *
     * Verifică și filtrul de status: un draft și o comandă anulată nu sunt venit, dar AMÂNDOUĂ
     * se numără în placa de comenzi.
     */
    public function test_the_money_series_follows_placed_at_while_the_count_follows_created_at(): void
    {
        $twoMonthsAgo = now()->startOfMonth()->subMonths(2)->addDays(3);

        TenantContext::run($this->marlin, function () use ($twoMonthsAgo): void {
            $account = Account::query()->firstOrFail();

            // Creată LUNA ASTA, plasată acum două luni: banii merg acolo, numărul rămâne aici.
            $backdated = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_CONFIRMED,
                'grand_total' => 1_234.56,
                'placed_at' => $twoMonthsAgo,
            ]);
            $backdated->created_by = $this->owner->getKey();
            $backdated->save();

            // ANULATĂ, plasată tot luna asta: se numără, dar nu e venit.
            $cancelled = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_CANCELLED,
                'grand_total' => 99_000.00,
                'placed_at' => now(),
            ]);
            $cancelled->created_by = $this->owner->getKey();
            $cancelled->save();
        });
        $this->clearDatabaseTenantContext();

        $this->partialReload('marlin', 'charts')
            ->assertOk()
            // Banii lunii curente: DOAR comanda confirmată din seed. Nu 5.452 (dacă seria ar
            // citi `created_at`), nu 103.217 (dacă filtrul de status ar lipsi).
            ->assertJsonPath('props.charts.orders.11', 4217.44)
            // …iar comanda retrodatată apare acolo unde a fost PLASATĂ.
            ->assertJsonPath('props.charts.orders.9', 1234.56)
            // Numărul merge pe `created_at`: toate trei sunt create azi.
            ->assertJsonPath('props.charts.ordersCount.11', 3)
            ->assertJsonPath('props.charts.ordersCount.9', 0);
    }

    /**
     * Feed-ul: NUMELE înregistrării atinse, ORDINEA și PLAFONUL. Niciuna dintre cele trei
     * n-avea gardă — iar `subjectName` e chiar motivul pentru care feed-ul a fost schimbat
     * („zece rânduri «Updated Deal» nu spun CARE afacere"), deci o implementare care întoarce
     * mereu `null` ar fi trecut prin toată suita.
     */
    public function test_the_feed_names_the_record_and_shows_the_ten_most_recent_first(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $deal = Deal::query()->where('title', 'Annual fastener supply agreement')->firstOrFail();

            // 12 intrări, cu timestamp-uri DISTINCTE și crescătoare, ca ordinea să fie
            // verificabilă: cea mai veche are indicele 0 în inserare și trebuie să cadă în
            // afara plafonului de 10.
            foreach (range(1, 12) as $index) {
                $row = ActivityLog::query()->create([
                    'user_id' => $this->owner->getKey(),
                    'action' => 'updated',
                    'auditable_type' => Deal::class,
                    'auditable_id' => $deal->getKey(),
                    'new_values' => ['title' => "Revizia {$index}"],
                    'ip_address' => '203.0.113.10',
                    'user_agent' => 'DashboardTest',
                ]);
                $row->forceFill(['created_at' => now()->subMinutes(60 - $index * 5)])->saveQuietly();
            }
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Plafonul de 10, nu toate cele 12.
                ->has('activity', 10)
                // Cea mai RECENTĂ prima: a 12-a inserată e cea mai nouă.
                ->where('activity.0.description', 'Updated Deal')
                // Numele propriu al afacerii, nu doar tipul ei — calea pozitivă a lui
                // `subjectName()`, pe care nimic n-o atingea.
                ->where('activity.0.subjectName', 'Annual fastener supply agreement')
                ->where('activity.0.kind', 'updated')
            );
    }

    public function test_the_switcher_lists_every_workspace_the_user_belongs_to(): void
    {
        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('workspaces', 2)
                ->where('workspaces.0.slug', 'cascade')   // ordonate după numele tenantului
                ->where('workspaces.1.slug', 'marlin')
            );
    }

    public function test_a_user_cannot_open_a_workspace_they_are_not_a_member_of(): void
    {
        $stranger = $this->makeMember($this->cascade, 'stranger@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();

        // 404, nu 403: existența workspace-ului nu se confirmă cuiva din afara lui.
        $this->actingAs($stranger)->get('/marlin/dashboard')->assertNotFound();
    }

    public function test_the_navigation_permissions_differ_between_roles(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->clearDatabaseTenantContext();

        // Cheile lui `navigation` CONȚIN puncte („billing.view"), deci nu pot fi adresate
        // cu notația cu punct a aserțiunilor Inertia — ea ar căuta `navigation → billing → view`.
        $this->assertNavigationPermissions($this->owner, ['billing.view' => true, 'members.view' => true]);

        // Criteriul de acceptanță din §7.3: Managerul NU vede „Billing & Subscription".
        $this->assertNavigationPermissions($manager, ['billing.view' => false, 'members.view' => true]);

        $this->assertNavigationPermissions($viewer, [
            'billing.view' => false,
            'members.view' => false,
            'accounts.view' => true,
        ]);
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertNavigationPermissions(User $user, array $expected): void
    {
        $response = $this->actingAs($user)->get('/marlin/dashboard');
        $this->assertSame(200, $response->getStatusCode(), 'Status pentru '.$user->email.': '.$response->getStatusCode().' '.substr(strip_tags($response->getContent()), 0, 300));
        $response
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation', function ($navigation) use ($expected): bool {
                    foreach ($expected as $permission => $allowed) {
                        if (($navigation[$permission] ?? null) !== $allowed) {
                            return false;
                        }
                    }

                    return true;
                })
            );
    }

    private function dealInStage(Account $account, Stage $stage, string $title, float $value, string $status): Deal
    {
        $deal = new Deal([
            'account_id' => $account->getKey(),
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'title' => $title,
            'value' => $value,
            'status' => $status,
        ]);
        $deal->created_by = $this->owner->getKey();
        $deal->save();

        return $deal;
    }

    private function stageEvent(Deal $deal, Stage $from, Stage $to, Carbon $at): void
    {
        $event = new DealStageEvent([
            'deal_id' => $deal->getKey(),
            'from_stage_id' => $from->getKey(),
            'to_stage_id' => $to->getKey(),
            'changed_at' => $at,
        ]);
        $event->changed_by = $this->owner->getKey();
        $event->save();
    }

    private function logActivity(User $user, string $action, ?string $auditableType = null): void
    {
        ActivityLog::query()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'DashboardTest',
        ]);
    }

    private function seedBusinessData(Tenant $tenant, float $dealValue, float $overdueBalance): void
    {
        TenantContext::run($tenant, function () use ($dealValue, $overdueBalance): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'name' => 'Qualification',
                'position' => 1,
                'probability' => 25,
            ]);
            // Etapa terminală există ca să se poată verifica faptul că NU apare în graficul de
            // pipeline — o bară „Won" acolo ar face ca orice pipeline sănătos să arate ca unul
            // înțepenit la capăt, iar absența ei e o decizie, deci se testează.
            $won = Stage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                'name' => 'Won',
                'position' => 2,
                'is_won' => true,
                'probability' => 100,
            ]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Annual fastener supply agreement',
                'value' => $dealValue,
                'status' => Deal::STATUS_OPEN,
                // În fereastra de 14 zile a listei „la care se cere acțiune". Dat pe afacerea
                // EXISTENTĂ, nu pe una nouă: o a doua afacere deschisă ar muta
                // `kpis.openPipelineValue`, iar testul de izolare de mai sus se sprijină pe
                // cifra exactă.
                'expected_close_date' => now()->addDays(3)->toDateString(),
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            // Afacere câștigată + evenimentul de etapă: seria „won" se numără pe luna
            // EVENIMENTULUI, nu pe `updated_at`, deci fără rândul din `deal_stage_events`
            // graficul ar rămâne pe zero oricât de multe afaceri câștigate ar exista.
            $wonDeal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $won->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Pilot order — hex bolts',
                'value' => 7_000.00,
                'status' => Deal::STATUS_WON,
                // Un termen în fereastra „closing soon", pe o afacere DEJA ÎNCHISĂ: fără el,
                // filtrul `status = open` din `closingSoon` n-ar fi avut ce să excludă, deci
                // scoaterea lui ar fi trecut neobservată.
                'expected_close_date' => now()->subDays(2)->toDateString(),
            ]);
            $wonDeal->created_by = $this->owner->getKey();
            $wonDeal->save();

            $event = new DealStageEvent([
                'deal_id' => $wonDeal->getKey(),
                'from_stage_id' => $stage->getKey(),
                'to_stage_id' => $won->getKey(),
                'changed_at' => now(),
            ]);
            $event->changed_by = $this->owner->getKey();
            $event->save();

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => 'confirmed',
                'grand_total' => 4_217.44,
                // Seria de bani se numără pe `placed_at` (momentul în care comanda a fost
                // plasată), nu pe `created_at` — fără el comanda nu intră în grafic.
                'placed_at' => now(),
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            // `order_id` nu e fillable pe Invoice (spre deosebire de `account_id` pe Order):
            // factura se emite dintr-o comandă, prin serviciu, nu din input de utilizator.
            // `total` DIFERIT de `balance_due`: egale, fixtura n-ar fi putut distinge între
            // „suma restantă" și „valoarea facturii", iar comentariul din controller care
            // explică alegerea lui `balance_due` n-ar fi avut nicio gardă.
            $invoice = new Invoice([
                'status' => Invoice::STATUS_OVERDUE,
                'total' => $overdueBalance + 1_500.00,
                'balance_due' => $overdueBalance,
                'due_date' => now()->subDays(14),
            ]);
            $invoice->order_id = $order->getKey();
            $invoice->save();

            // Stoc sub prag — există mai ales ca să se parcurgă lanțul `variant.product` din
            // `attention()`: proiectul interzice lazy loading (`Model::preventLazyLoading`),
            // deci un `with()` lipsă acolo ar arunca, nu ar încetini.
            $location = Location::query()->create(['name' => 'Main warehouse', 'is_default' => true]);
            $product = Product::query()->create(['name' => 'Hex bolt M8', 'unit_of_measure' => 'each']);
            $variant = Variant::query()->create([
                'product_id' => $product->getKey(),
                'sku' => 'HEX-M8-50',
                'price' => 0.42,
                'cost' => 0.19,
                // `LowStockRule`: prag NUL = fără alertă, niciodată „low" prin comparație
                // implicită cu 0. Fără rândul ăsta varianta n-ar apărea deloc în listă.
                'low_stock_threshold' => 10,
            ]);
            InventoryLevel::query()->create([
                'variant_id' => $variant->getKey(),
                'location_id' => $location->getKey(),
                'on_hand' => 6,
                'reserved' => 4,
            ]);
        });
    }
}
