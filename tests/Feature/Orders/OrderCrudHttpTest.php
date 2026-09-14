<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ConfirmOrderAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * CRUD de comenzi prin HTTP — plan §9 task 1, contractul de props (plan §1.2 regula 5)
 * și RBAC pe fiecare acțiune de scriere (§7.4/§7.5), la fel ca `DealCrudHttpTest`.
 */
class OrderCrudHttpTest extends TestCase
{
    use CreatesOrders;

    private Tenant $marlin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_viewer_can_list_orders_without_a_create_button(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer2@throughput.dev', Permissions::VIEWER);
        $this->draftOrder($this->makeMember($this->marlin, 'owner10@throughput.dev', Permissions::OWNER));
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->get('/marlin/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('can.create', false)
            );
    }

    public function test_an_agent_defaults_to_their_own_orders(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner11@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent4@throughput.dev', Permissions::AGENT);
        $this->draftOrder($owner);
        $this->draftOrder($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->get('/marlin/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.filter.owner', 'me'));
    }

    /**
     * Code review P2-001 — `OrderSummaryResource::can.cancel` chema
     * `Gate::allows('cancel', …)` per rând, iar `OrderPolicy::cancel()` repeta
     * `shipments()->exists()` — 50 de interogări suplimentare pe o pagină de 50.
     * `OrderList::baseQuery()` precarcă acum `shipments_exists` cu `withExists()`.
     * Verificat prin comparație, ca la `DealKanbanTest::test_the_board_runs_a_constant_number_of_queries…()`:
     * numărul de interogări NU crește proporțional cu numărul de comenzi din pagină.
     *
     * `orders` e un prop deferred (`Inertia::defer`, FR-PERF-01) — nu se rezolvă pe o
     * încărcare inițială, doar pe un reload parțial pentru exact acest prop, deci
     * cererea de test poartă explicit header-ele de reload parțial ale Inertia.
     */
    public function test_the_orders_list_does_not_n_plus_one_the_cancel_permission_per_row(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner12@throughput.dev', Permissions::OWNER);

        // `Inertia::getVersion()` nu e populat în afara unei cereri reale (îl setează
        // `HandleInertiaRequests::handle()` la începutul FIECĂREI cereri) — un apel direct
        // aici, înainte de orice request, ar întoarce mereu '', diferit de versiunea reală
        // calculată din `public/build/manifest.json`, deci server-ul ar răspunde 409 (Inertia
        // tratează asta ca „reload complet necesar"), nu ceea ce testează asta. Un prim
        // reload parțial cu o versiune sigur greșită scoate versiunea reală din headerul de
        // răspuns (`onVersionChange()` o pune acolo chiar și pe un 409).
        $version = $this->actingAs($owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Orders/Index',
                'X-Inertia-Partial-Data' => 'orders',
            ])
            ->get('/marlin/orders')
            ->headers->get('x-inertia-version');

        $queryCountFor = function (int $orderCount) use ($owner, $version): int {
            TenantContext::run($this->marlin, function () use ($owner, $orderCount): void {
                Order::query()->delete();

                for ($i = 0; $i < $orderCount; $i++) {
                    $order = new Order([
                        'account_id' => $this->account->getKey(),
                        'owner_user_id' => $owner->getKey(),
                        'status' => OrderStatus::Draft,
                        'currency' => 'USD',
                    ]);
                    $order->created_by = $owner->getKey();
                    $order->save();
                }
            });
            $this->clearDatabaseTenantContext();

            // `flushQueryLog()` — `disableQueryLog()` NU golește jurnalul, doar oprește
            // înregistrarea; fără flush aici, a doua măsurătoare din acest test ar
            // acumula și interogările primei.
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($owner)
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => $version,
                    'X-Inertia-Partial-Component' => 'Orders/Index',
                    'X-Inertia-Partial-Data' => 'orders',
                ])
                ->get('/marlin/orders')
                ->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $withOneOrder = $queryCountFor(1);
        $withTenOrders = $queryCountFor(10);

        $this->assertSame(
            $withOneOrder,
            $withTenOrders,
            "Query count should stay constant regardless of order count; got {$withOneOrder} for 1 order and {$withTenOrders} for 10."
        );
    }

    public function test_create_is_prefilled_from_the_account_query_parameter(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);

        $this->actingAs($owner)
            ->get("/marlin/orders/create?account={$this->account->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Create')
                ->where('account.id', $this->account->getKey())
                ->where('can.changeOwner', true)
            );
    }

    public function test_a_viewer_cannot_create_an_order(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)
            ->get("/marlin/orders/create?account={$this->account->getKey()}")
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/marlin/orders', ['account_id' => $this->account->getKey()])
            ->assertForbidden();
    }

    public function test_storing_a_draft_without_lines_succeeds(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        $response = $this->actingAs($agent)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
        ]);

        $response->assertRedirect();

        TenantContext::run($this->marlin, function () use ($agent): void {
            $order = Order::query()->where('account_id', $this->account->getKey())->firstOrFail();
            $this->assertSame($agent->getKey(), $order->owner_user_id);
            $this->assertSame(OrderStatus::Draft, $order->status);
            $this->assertNull($order->order_number);
            $this->assertCount(0, $order->orderLines);
        });
    }

    public function test_storing_a_draft_with_lines_precomputes_unit_price_from_the_variant(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner3@throughput.dev', Permissions::OWNER);
        $variantId = TenantContext::run($this->marlin, fn () => $this->makeVariant(price: 42.5)->getKey());

        $response = $this->actingAs($owner)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
            'lines' => [
                ['variant_id' => $variantId, 'quantity' => 3],
            ],
        ]);

        $response->assertRedirect();

        TenantContext::run($this->marlin, function () use ($variantId): void {
            $order = Order::query()->where('account_id', $this->account->getKey())->firstOrFail();
            $line = $order->orderLines->first();
            $this->assertSame($variantId, $line->variant_id);
            $this->assertSame(3, $line->quantity);
            $this->assertSame('42.50', $line->unit_price);
            $this->assertSame('127.50', $order->subtotal);
        });
    }

    /**
     * Code review P3 — `discount` era validat doar `min:0`, deci `line_total`/
     * `grand_total` (`BuildsOrderLines`) puteau ieși negative. Aici: 2 × 10.00 = 20.00
     * subtotal, discount de 25 > 20 — refuzat, nimic scris.
     */
    public function test_a_line_discount_exceeding_its_subtotal_is_refused_on_create(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner13@throughput.dev', Permissions::OWNER);
        $variantId = TenantContext::run($this->marlin, fn () => $this->makeVariant(price: 10.0)->getKey());

        $response = $this->actingAs($owner)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
            'lines' => [
                ['variant_id' => $variantId, 'quantity' => 2, 'unit_price' => 10.0, 'discount' => 25.0],
            ],
        ]);

        $response->assertSessionHasErrors('lines.0.discount');

        TenantContext::run($this->marlin, function (): void {
            $this->assertSame(0, Order::query()->count());
        });
    }

    /** Simetric cu testul de mai sus, pe editarea unui draft existent. */
    public function test_a_line_discount_exceeding_its_subtotal_is_refused_on_update(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner14@throughput.dev', Permissions::OWNER);
        $variantId = TenantContext::run($this->marlin, fn () => $this->makeVariant(price: 10.0)->getKey());
        $order = $this->draftOrder($owner, [['variant_id' => $variantId, 'quantity' => 2]]);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->put("/marlin/orders/{$order->getKey()}", [
            'account_id' => $this->account->getKey(),
            'lines' => [
                ['variant_id' => $variantId, 'quantity' => 2, 'unit_price' => 10.0, 'discount' => 25.0],
            ],
        ]);

        $response->assertSessionHasErrors('lines.0.discount');

        TenantContext::run($this->marlin, function () use ($order): void {
            $this->assertCount(1, $order->fresh('orderLines')->orderLines, 'Liniile vechi rămân neatinse.');
        });
    }

    /**
     * Code review P2-002 — `orders.change_owner` (catalog + `OrderPolicy::changeOwner()`)
     * e acum sursa unică, ca la `deals.change_owner`: formularul NU oferă opțiunea, iar o
     * cerere directă cu `owner_user_id` forjat e refuzată server-side, nu doar ascunsă din
     * UI.
     */
    public function test_an_agent_cannot_change_the_owner_of_an_order_via_the_form_or_a_direct_request(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent2@throughput.dev', Permissions::AGENT);
        $otherAgent = $this->makeMember($this->marlin, 'other-agent@throughput.dev', Permissions::AGENT);

        // Formular — nicio opțiune de owner oferită Agentului.
        $this->actingAs($agent)
            ->get("/marlin/orders/create?account={$this->account->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.changeOwner', false)
                ->where('owners', [])
            );

        // Cerere directă — `owner_user_id` forjat e ignorat, nu doar ascuns din UI.
        $this->actingAs($agent)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $otherAgent->getKey(),
        ])->assertRedirect();

        $orderId = TenantContext::run($this->marlin, function () use ($agent): string {
            $order = Order::query()->where('account_id', $this->account->getKey())->firstOrFail();
            $this->assertSame($agent->getKey(), $order->owner_user_id);

            return $order->getKey();
        });
        $this->clearDatabaseTenantContext();

        // Editarea unui draft existent — la fel, `owner_user_id` forjat nu se aplică.
        $this->actingAs($agent)
            ->get("/marlin/orders/{$orderId}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.changeOwner', false));

        $this->actingAs($agent)->put("/marlin/orders/{$orderId}", [
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $otherAgent->getKey(),
            'lines' => [],
        ])->assertRedirect();

        TenantContext::run($this->marlin, function () use ($agent, $orderId): void {
            $this->assertSame($agent->getKey(), Order::query()->findOrFail($orderId)->owner_user_id);
        });
    }

    /**
     * Simetric cu testul de mai sus: Manager ARE `orders.change_owner`
     * (`Permissions::forRoles()`), deci reasignarea trece, atât la creare cât și la
     * editare.
     */
    public function test_a_manager_can_change_the_owner_of_an_order(): void
    {
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'agent5@throughput.dev', Permissions::AGENT);

        $this->actingAs($manager)
            ->get("/marlin/orders/create?account={$this->account->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.changeOwner', true));

        $this->actingAs($manager)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $agent->getKey(),
        ])->assertRedirect();

        $orderId = TenantContext::run($this->marlin, function () use ($agent): string {
            $order = Order::query()->where('account_id', $this->account->getKey())->firstOrFail();
            $this->assertSame($agent->getKey(), $order->owner_user_id);

            return $order->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($manager)->put("/marlin/orders/{$orderId}", [
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $manager->getKey(),
            'lines' => [],
        ])->assertRedirect();

        TenantContext::run($this->marlin, function () use ($manager, $orderId): void {
            $this->assertSame($manager->getKey(), Order::query()->findOrFail($orderId)->owner_user_id);
        });
    }

    public function test_show_exposes_the_contract(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner4@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrder($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->get("/marlin/orders/{$order->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('order.id', $order->getKey())
                ->where('order.status', 'draft')
                ->where('can.edit', true)
                ->where('can.delete', true)
                ->where('can.confirm', true)
                ->where('can.cancel', true)
            );
    }

    public function test_an_agent_sees_a_foreign_order_but_without_write_rights(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner5@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent3@throughput.dev', Permissions::AGENT);
        $order = $this->draftOrder($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->get("/marlin/orders/{$order->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', false)
                ->where('can.confirm', false)
                ->where('can.cancel', false)
                ->where('can.delete', false)
            );
    }

    public function test_editing_a_confirmed_order_is_forbidden(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner6@throughput.dev', Permissions::OWNER);
        $order = $this->confirmedOrder($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->get("/marlin/orders/{$order->getKey()}/edit")
            ->assertForbidden();

        $this->actingAs($owner)
            ->put("/marlin/orders/{$order->getKey()}", ['account_id' => $this->account->getKey(), 'lines' => []])
            ->assertForbidden();
    }

    public function test_updating_a_draft_replaces_its_lines(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner7@throughput.dev', Permissions::OWNER);
        $variantId = TenantContext::run($this->marlin, fn () => $this->makeVariant()->getKey());
        $order = $this->draftOrder($owner, [['variant_id' => $variantId, 'quantity' => 2]]);

        $newVariantId = TenantContext::run($this->marlin, fn () => $this->makeVariant('HEX-NUT-M8', 5.0)->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->put("/marlin/orders/{$order->getKey()}", [
                'account_id' => $this->account->getKey(),
                'notes' => 'Rush order',
                'lines' => [
                    ['variant_id' => $newVariantId, 'quantity' => 4, 'unit_price' => 5.0],
                ],
            ])
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order, $newVariantId): void {
            $fresh = $order->fresh('orderLines');
            $this->assertSame('Rush order', $fresh->notes);
            $this->assertCount(1, $fresh->orderLines);
            $this->assertSame($newVariantId, $fresh->orderLines->first()->variant_id);
            $this->assertSame('20.00', $fresh->subtotal);
        });
    }

    public function test_deleting_a_draft_is_allowed_but_a_confirmed_order_is_not(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner8@throughput.dev', Permissions::OWNER);
        $draft = $this->draftOrder($owner);
        $confirmed = $this->confirmedOrder($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->delete("/marlin/orders/{$confirmed->getKey()}")->assertForbidden();
        $this->actingAs($owner)->delete("/marlin/orders/{$draft->getKey()}")->assertRedirect('/marlin/orders');

        TenantContext::run($this->marlin, function () use ($draft): void {
            $this->assertNull(Order::query()->find($draft->getKey()));
        });
    }

    public function test_an_order_from_another_tenant_is_not_found(): void
    {
        $stranger = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $strangerOwner = $this->makeMember($stranger, 'stranger@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'owner9@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrder($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($strangerOwner)
            ->get("/cascade/orders/{$order->getKey()}")
            ->assertNotFound();
    }

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $lines
     */
    private function draftOrder(User $owner, array $lines = []): Order
    {
        return TenantContext::run($this->marlin, function () use ($owner, $lines): Order {
            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            foreach ($lines as $line) {
                $order->orderLines()->create([
                    'variant_id' => $line['variant_id'],
                    'description' => 'Test line',
                    'quantity' => $line['quantity'],
                    'unit_price' => 10,
                    'discount' => 0,
                    'line_total' => 10 * $line['quantity'],
                ]);
            }

            return $order;
        });
    }

    /**
     * Trei apeluri `TenantContext::run()` SEPARATE, niciodată imbricate: `draftOrder()`
     * deschide propriul context, deci a-l apela din interiorul altuia ar fi o tranzacție
     * imbricată inutilă. Fiecare pas își face propria tranzacție scurtă, la fel ca în
     * testele HTTP de mai sus.
     */
    private function confirmedOrder(User $owner): Order
    {
        $variantId = TenantContext::run($this->marlin, function (): string {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 100);

            return $variant->getKey();
        });

        $order = $this->draftOrder($owner, [['variant_id' => $variantId, 'quantity' => 2]]);

        return TenantContext::run($this->marlin, fn () => (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false));
    }
}
