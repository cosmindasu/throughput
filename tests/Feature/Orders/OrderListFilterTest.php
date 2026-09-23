<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-ORD-02, `App\Support\Lists\OrderList` — niciun test HTTP nu atingea filtrele
 * (`status`, `account`, `owner`, `from`/`to`, `q`) sau paginarea pe cursor înainte de acest
 * fișier; singurele `GET` cu querystring pe `/orders` din suită erau `?columns=…`
 * (`OrderCrudHttpTest`). Fiecare test de aici are date care TREBUIE excluse de filtru, nu
 * doar date care trebuie incluse — un filtru care nu filtrează (sau un cursor care sare
 * rânduri) trebuie să pice testul, nu doar un `assertOk()`.
 *
 * Lista `orders` e un prop `Inertia::defer()` (FR-PERF-01): citirea conținutului ei cere un
 * reload parțial (`X-Inertia-Partial-Data: orders`), exact tiparul din `OrderCrudHttpTest`/
 * `ProductLowStockCountTest` — un `warmup` fără header-ul de versiune corect ca să scoatem
 * `X-Inertia-Version` din răspuns, apoi cererea reală cu versiunea corectă.
 */
class OrderListFilterTest extends TestCase
{
    private Tenant $marlin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'fixture-owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_status_filter_returns_only_orders_with_that_exact_status(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-status@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'confirmed' => $this->makeOrder($owner, ['status' => OrderStatus::Confirmed]),
            'draft' => $this->makeOrder($owner, ['status' => OrderStatus::Draft]),
            'cancelled' => $this->makeOrder($owner, ['status' => OrderStatus::Cancelled]),
        ]);
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[status]=confirmed'));

        $this->assertSame([$orders['confirmed']->getKey()], $ids);
        $this->assertNotContains($orders['draft']->getKey(), $ids);
        $this->assertNotContains($orders['cancelled']->getKey(), $ids);
    }

    /**
     * `OrderList::STATUS_ACTIVE` — pseudo-status care agregă `draft`/`confirmed`/
     * `partially_fulfilled` (`OpenRecordCounts::activeOrderStatuses()`), NU o singură
     * valoare de `OrderStatus`. `fulfilled` și `cancelled` sunt terminale — trebuie excluse.
     */
    public function test_status_filter_active_aggregates_the_three_open_statuses(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-active@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'draft' => $this->makeOrder($owner, ['status' => OrderStatus::Draft]),
            'confirmed' => $this->makeOrder($owner, ['status' => OrderStatus::Confirmed]),
            'partiallyFulfilled' => $this->makeOrder($owner, ['status' => OrderStatus::PartiallyFulfilled]),
            'fulfilled' => $this->makeOrder($owner, ['status' => OrderStatus::Fulfilled]),
            'cancelled' => $this->makeOrder($owner, ['status' => OrderStatus::Cancelled]),
        ]);
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[status]=active'));

        $this->assertEqualsCanonicalizing([
            $orders['draft']->getKey(),
            $orders['confirmed']->getKey(),
            $orders['partiallyFulfilled']->getKey(),
        ], $ids);
        $this->assertNotContains($orders['fulfilled']->getKey(), $ids);
        $this->assertNotContains($orders['cancelled']->getKey(), $ids);
    }

    public function test_account_filter_excludes_orders_from_other_accounts(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-account@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, function () use ($owner): array {
            $otherAccount = new Account(['name' => 'Cascade Hydraulic Components']);
            $otherAccount->created_by = $owner->getKey();
            $otherAccount->save();

            return [
                'matching' => $this->makeOrder($owner),
                'otherAccount' => $this->makeOrder($owner, ['account' => $otherAccount]),
            ];
        });
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[account]='.$this->account->getKey()));

        $this->assertSame([$orders['matching']->getKey()], $ids);
        $this->assertNotContains($orders['otherAccount']->getKey(), $ids);
    }

    /**
     * `whereDate('created_at', '>=', $from)` / `whereDate(..., '<=', $to)` — ambele capete
     * ale intervalului sunt INCLUSIVE, deci comenzile exact pe graniță trebuie să rămână, nu
     * doar cea strict interioară. Comenzile dinaintea `from` și de după `to` trebuie excluse.
     */
    public function test_date_range_filter_excludes_orders_outside_both_ends(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-dates@throughput.dev', Permissions::OWNER);
        $base = now();

        $orders = TenantContext::run($this->marlin, fn () => [
            'before' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDays(10)]),
            'lowerBound' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDays(5)->startOfDay()]),
            'inside' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDays(3)]),
            'upperBound' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDay()->endOfDay()]),
            'after' => $this->makeOrder($owner, ['created_at' => $base]),
        ]);
        $this->clearDatabaseTenantContext();

        $from = $base->copy()->subDays(5)->toDateString();
        $to = $base->copy()->subDay()->toDateString();

        $ids = $this->orderIds($this->fetchOrders($owner, "?filter[from]={$from}&filter[to]={$to}"));

        $this->assertEqualsCanonicalizing([
            $orders['lowerBound']->getKey(),
            $orders['inside']->getKey(),
            $orders['upperBound']->getKey(),
        ], $ids);
        $this->assertNotContains($orders['before']->getKey(), $ids);
        $this->assertNotContains($orders['after']->getKey(), $ids);
    }

    public function test_owner_filter_me_returns_only_the_authenticated_users_own_orders(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-me@throughput.dev', Permissions::OWNER);
        $colleague = $this->makeMember($this->marlin, 'colleague-me@throughput.dev', Permissions::MANAGER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'mine' => $this->makeOrder($owner),
            'theirs' => $this->makeOrder($colleague),
        ]);
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[owner]=me'));

        $this->assertSame([$orders['mine']->getKey()], $ids);
        $this->assertNotContains($orders['theirs']->getKey(), $ids);
    }

    /**
     * `OrderList::defaultFilters()` restrânge un Agent la `owner=me` doar când cheia
     * LIPSEȘTE din URL (`ListQuery::fromRequest()`). `owner=all` explicit trebuie să anuleze
     * acel implicit — verificat prin comparație directă cu cererea FĂRĂ filtru explicit,
     * ca `OrderCrudHttpTest::test_an_agent_defaults_to_their_own_orders`, dar pe date reale.
     */
    public function test_owner_filter_all_overrides_the_agents_default_own_records_restriction(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent-all@throughput.dev', Permissions::AGENT);
        $colleague = $this->makeMember($this->marlin, 'colleague-all@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'mine' => $this->makeOrder($agent),
            'theirs' => $this->makeOrder($colleague),
        ]);
        $this->clearDatabaseTenantContext();

        $withoutFilter = $this->orderIds($this->fetchOrders($agent));
        $this->assertSame([$orders['mine']->getKey()], $withoutFilter, 'Implicitul Agentului trebuie să rămână owner=me.');

        $withAll = $this->orderIds($this->fetchOrders($agent, '?filter[owner]=all'));
        $this->assertEqualsCanonicalizing([$orders['mine']->getKey(), $orders['theirs']->getKey()], $withAll);
    }

    /**
     * ADR-011, simetric cu `AccountList`/`DealList` — un owner dezactivat, nereatribuit, nu
     * dispare din listă: apare separat sub „Unassigned". Comanda unui membru ACTIV trebuie
     * exclusă din acest filtru.
     */
    public function test_owner_filter_unassigned_returns_orders_owned_by_a_deactivated_member(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-unassigned@throughput.dev', Permissions::OWNER);
        $leaver = $this->makeMember($this->marlin, 'leaver-unassigned@throughput.dev', Permissions::AGENT);

        $orders = TenantContext::run($this->marlin, fn () => [
            'activeOwned' => $this->makeOrder($owner),
            'orphaned' => $this->makeOrder($leaver),
        ]);

        TenantContext::run($this->marlin, function () use ($leaver): void {
            Membership::query()->where('user_id', $leaver->getKey())->update([
                'status' => Membership::STATUS_DEACTIVATED,
                'deactivated_at' => now(),
            ]);
        });
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[owner]=unassigned'));

        $this->assertSame([$orders['orphaned']->getKey()], $ids);
        $this->assertNotContains($orders['activeOwned']->getKey(), $ids);
    }

    /**
     * `owner` acceptă orice ULID valid, nu doar `me`/`all`/`unassigned`
     * (`OrderList::accepts()`) — un Manager/Owner poate filtra pe UN COLEG anume. Verifică
     * ramura `default` din `OrderList::applyFilters()` (`Str::lower($owner)`), distinctă de
     * cele trei valori speciale deja acoperite mai sus.
     */
    public function test_owner_filter_by_an_explicit_member_id_returns_only_their_orders(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-explicit@throughput.dev', Permissions::OWNER);
        $colleague = $this->makeMember($this->marlin, 'colleague-explicit@throughput.dev', Permissions::MANAGER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'mine' => $this->makeOrder($owner),
            'theirs' => $this->makeOrder($colleague),
        ]);
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[owner]='.$colleague->getKey()));

        $this->assertSame([$orders['theirs']->getKey()], $ids);
        $this->assertNotContains($orders['mine']->getKey(), $ids);
    }

    public function test_free_text_search_filters_by_order_number(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-search@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, fn () => [
            'match' => $this->makeOrder($owner, ['order_number' => 'SO-2026-0042']),
            'other' => $this->makeOrder($owner, ['order_number' => 'SO-2026-0099']),
        ]);
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders($owner, '?filter[q]=0042'));

        $this->assertSame([$orders['match']->getKey()], $ids);
        $this->assertNotContains($orders['other']->getKey(), $ids);
    }

    /**
     * FR-PERF-03 — 55 de comenzi (peste `ListQuery::PER_PAGE` = 50), fiecare cu un
     * `created_at` distinct (secundă întreagă, deterministă, calculată dintr-un singur `$base`
     * fixat înainte de buclă — nu `now()` reevaluat la fiecare iterație, ca să nu depindă de
     * viteza reală de execuție). A doua pagină trebuie să continue EXACT de unde s-a oprit
     * prima: fără suprapunere (niciun id de pe pagina 1 nu reapare pe pagina 2) și fără
     * rânduri sărite (reuniunea celor două pagini = toate cele 55 de id-uri, în ordine).
     */
    public function test_cursor_pagination_continues_the_second_page_without_overlap_or_gaps(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-cursor@throughput.dev', Permissions::OWNER);
        $base = now();

        $ids = TenantContext::run($this->marlin, function () use ($owner, $base): array {
            $ids = [];

            for ($i = 0; $i < 55; $i++) {
                $ids[] = $this->makeOrder($owner, ['created_at' => $base->copy()->subSeconds($i)])->getKey();
            }

            return $ids;
        });
        $this->clearDatabaseTenantContext();

        // $ids[0] are cel mai recent `created_at` (subSeconds(0)) — sortarea implicită e
        // `-created_at`, deci $ids[0] trebuie să fie primul rând din pagina 1.
        $firstPage = $this->fetchOrders($owner);
        $firstIds = $this->orderIds($firstPage);

        $this->assertCount(50, $firstIds);
        $this->assertSame(array_slice($ids, 0, 50), $firstIds);
        $this->assertNotNull($firstPage['nextCursor']);

        $secondPage = $this->fetchOrders($owner, '?cursor='.$firstPage['nextCursor']);
        $secondIds = $this->orderIds($secondPage);

        $this->assertCount(5, $secondIds);
        $this->assertSame(array_slice($ids, 50, 5), $secondIds);
        $this->assertNull($secondPage['nextCursor']);

        $this->assertEmpty(
            array_intersect($firstIds, $secondIds),
            'Cele două pagini nu trebuie să se suprapună.'
        );
        $this->assertSame($ids, array_merge($firstIds, $secondIds), 'Reuniunea paginilor trebuie să reproducă exact cele 55 de comenzi, în ordine.');
    }

    /** Combinație — `status` ȘI `account` se aplică simultan (AND), nu doar unul dintre ele. */
    public function test_combining_status_and_account_filters_applies_both_as_an_and(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-combo1@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, function () use ($owner): array {
            $otherAccount = new Account(['name' => 'Vulcan Metalworks']);
            $otherAccount->created_by = $owner->getKey();
            $otherAccount->save();

            return [
                'matching' => $this->makeOrder($owner, ['status' => OrderStatus::Confirmed]),
                'wrongStatus' => $this->makeOrder($owner, ['status' => OrderStatus::Draft]),
                'wrongAccount' => $this->makeOrder($owner, ['status' => OrderStatus::Confirmed, 'account' => $otherAccount]),
            ];
        });
        $this->clearDatabaseTenantContext();

        $ids = $this->orderIds($this->fetchOrders(
            $owner,
            '?filter[status]=confirmed&filter[account]='.$this->account->getKey()
        ));

        $this->assertSame([$orders['matching']->getKey()], $ids);
        $this->assertNotContains($orders['wrongStatus']->getKey(), $ids);
        $this->assertNotContains($orders['wrongAccount']->getKey(), $ids);
    }

    /** Combinație — `owner=me` ȘI intervalul de dată, simultan (AND). */
    public function test_combining_owner_me_and_date_range_filters_applies_both_as_an_and(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-combo2@throughput.dev', Permissions::OWNER);
        $colleague = $this->makeMember($this->marlin, 'colleague-combo2@throughput.dev', Permissions::MANAGER);
        $base = now();

        $orders = TenantContext::run($this->marlin, fn () => [
            'matching' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDays(2)]),
            'wrongOwner' => $this->makeOrder($colleague, ['created_at' => $base->copy()->subDays(2)]),
            'wrongDate' => $this->makeOrder($owner, ['created_at' => $base->copy()->subDays(20)]),
        ]);
        $this->clearDatabaseTenantContext();

        $from = $base->copy()->subDays(5)->toDateString();
        $to = $base->toDateString();

        $ids = $this->orderIds($this->fetchOrders(
            $owner,
            "?filter[owner]=me&filter[from]={$from}&filter[to]={$to}"
        ));

        $this->assertSame([$orders['matching']->getKey()], $ids);
        $this->assertNotContains($orders['wrongOwner']->getKey(), $ids);
        $this->assertNotContains($orders['wrongDate']->getKey(), $ids);
    }

    /**
     * `ListQuery::fromRequest()` — o valoare care nu trece `OrderList::accepts()` (status
     * necunoscut, cont care nu e ULID, dată neparsabilă de `strtotime()`) se IGNORĂ, nu 422
     * și nu 500: comportamentul trebuie să fie identic cu „fără acel filtru", consecvent
     * pentru toate cele trei validări (`OrderStatus::tryFrom`, `Str::isUlid`, `strtotime`).
     */
    public function test_invalid_filter_values_are_ignored_instead_of_filtering_or_erroring(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner-invalid@throughput.dev', Permissions::OWNER);

        $orders = TenantContext::run($this->marlin, function () use ($owner): array {
            $otherAccount = new Account(['name' => 'Rogue Fabrication']);
            $otherAccount->created_by = $owner->getKey();
            $otherAccount->save();

            return [
                'first' => $this->makeOrder($owner, ['status' => OrderStatus::Draft, 'created_at' => now()->subDays(30)]),
                'second' => $this->makeOrder($owner, [
                    'status' => OrderStatus::Cancelled,
                    'account' => $otherAccount,
                    'created_at' => now(),
                ]),
            ];
        });
        $this->clearDatabaseTenantContext();

        // Nicio eroare 500/422 — și `filters.filter` rămâne gol, dovadă că valorile au fost
        // RESPINSE, nu doar aplicate tăcut pe o interpretare surprinzătoare.
        $this->actingAs($owner)
            ->get('/marlin/orders?filter[status]=not-a-real-status&filter[account]=not-a-ulid&filter[from]=not-a-date')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('filters.filter', [])
            );

        $ids = $this->orderIds($this->fetchOrders(
            $owner,
            '?filter[status]=not-a-real-status&filter[account]=not-a-ulid&filter[from]=not-a-date'
        ));

        $this->assertEqualsCanonicalizing([
            $orders['first']->getKey(),
            $orders['second']->getKey(),
        ], $ids, 'Filtrele invalide trebuie ignorate — cererea trebuie să se comporte ca fără niciun filtru.');
    }

    /**
     * Creează o comandă direct (nu prin `ConfirmOrderAction`) — aceste teste verifică doar
     * interogarea SQL din `OrderList`, nu mașina de stări. `created_by` NU e în `#[Fillable]`
     * al `Order` (la fel ca în `OrderCrudHttpTest::draftOrder()`), deci se atribuie separat,
     * prin proprietate directă, nu prin masă.
     *
     * @param  array<string, mixed>  $attributes  poate conține `account` (un `Account`, altul
     *                                            decât `$this->account`) și `created_at`, în
     *                                            plus față de coloanele reale ale `Order`.
     */
    private function makeOrder(User $owner, array $attributes = []): Order
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $account = $attributes['account'] ?? $this->account;
        unset($attributes['account']);

        $order = new Order(array_merge([
            'account_id' => $account->getKey(),
            'owner_user_id' => $owner->getKey(),
            'status' => OrderStatus::Draft,
            'currency' => 'USD',
        ], $attributes));
        $order->created_by = $owner->getKey();

        if ($createdAt !== null) {
            $order->created_at = $createdAt;
        }

        $order->save();

        return $order;
    }

    /**
     * Reload parțial Inertia pentru propul `orders` (deferred, FR-PERF-01) — tiparul din
     * `OrderCrudHttpTest`/`ProductLowStockCountTest`: un `warmup` fără versiune corectă
     * scoate `X-Inertia-Version` din antetul de răspuns, apoi cererea reală cu versiunea
     * corectă întoarce JSON brut cu `props.orders`.
     *
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null, prevCursor: string|null}
     */
    private function fetchOrders(User $user, string $query = ''): array
    {
        $version = $this->actingAs($user)
            ->withHeaders($this->partialReloadHeaders('warmup'))
            ->get('/marlin/orders')
            ->headers->get('x-inertia-version');

        return $this->actingAs($user)
            ->withHeaders($this->partialReloadHeaders((string) $version))
            ->get('/marlin/orders'.$query)
            ->assertOk()
            ->json('props.orders');
    }

    /** @return array<string, string> */
    private function partialReloadHeaders(string $version): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Orders/Index',
            'X-Inertia-Partial-Data' => 'orders',
        ];
    }

    /**
     * @param  array{data: list<array<string, mixed>>, nextCursor: string|null, prevCursor: string|null}  $orders
     * @return list<string>
     */
    private function orderIds(array $orders): array
    {
        return collect($orders['data'])->pluck('id')->all();
    }
}
