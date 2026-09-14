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

    public function test_an_agent_cannot_assign_a_different_owner_even_by_forging_the_field(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent2@throughput.dev', Permissions::AGENT);
        $otherAgent = $this->makeMember($this->marlin, 'other-agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->post('/marlin/orders', [
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $otherAgent->getKey(),
        ])->assertRedirect();

        TenantContext::run($this->marlin, function () use ($agent): void {
            $order = Order::query()->where('account_id', $this->account->getKey())->firstOrFail();
            $this->assertSame($agent->getKey(), $order->owner_user_id);
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
