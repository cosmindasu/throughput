<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * `PATCH /orders/{order}/confirm` și `PATCH /orders/{order}/cancel` prin lanțul real
 * de middleware — DoD: teste HTTP; `can` verificat pe roluri; izolare de tenant; BR-ORD-01/
 * BR-ORD-02/BR-STOCK-04; tranziții ilegale refuzate server-side. La fel ca `DealStageHttpTest`.
 */
class OrderTransitionsHttpTest extends TestCase
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

    public function test_a_viewer_cannot_confirm_or_cancel_an_order(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $order = $this->draftOrderWithLine($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->patch("/marlin/orders/{$order->getKey()}/confirm")->assertForbidden();
        $this->actingAs($viewer)->patch("/marlin/orders/{$order->getKey()}/cancel")->assertForbidden();
    }

    public function test_an_agent_cannot_confirm_an_order_they_do_not_own(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner3@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $order = $this->draftOrderWithLine($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->patch("/marlin/orders/{$order->getKey()}/confirm")->assertForbidden();
    }

    public function test_confirming_reserves_stock_and_assigns_a_sequential_number(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner4@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner, quantity: 5, onHand: 50);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/confirm")
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order): void {
            $fresh = $order->fresh();
            $this->assertSame(OrderStatus::Confirmed, $fresh->status);
            $this->assertNotNull($fresh->order_number);
            $this->assertNotNull($fresh->placed_at);
        });
    }

    public function test_confirming_over_available_stock_asks_for_explicit_acknowledgement_then_succeeds(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner5@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner, quantity: 20, onHand: 5);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/confirm")
            ->assertSessionHasErrors('acknowledge_backorder');

        TenantContext::run($this->marlin, function () use ($order): void {
            $this->assertSame(OrderStatus::Draft, $order->fresh()->status, 'Nici blocare, dar nici permitere tăcută.');
        });

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/confirm", ['acknowledge_backorder' => true])
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order): void {
            $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        });
    }

    public function test_cancelling_a_confirmed_order_releases_reserved_stock(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner6@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner, quantity: 5, onHand: 50);
        $this->actingAs($owner)->patch("/marlin/orders/{$order->getKey()}/confirm");
        $this->clearDatabaseTenantContext();

        $variantId = TenantContext::run($this->marlin, fn () => $order->fresh()->orderLines->first()->variant_id);

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/cancel")
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order, $variantId): void {
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
            $level = InventoryLevel::query()->where('variant_id', $variantId)->first();
            $this->assertSame(0, $level->reserved);
        });
    }

    /**
     * BR-ORD-01 / §7.5 — `OrderPolicy::cancel()` verifică `shipments()->exists()`.
     */
    public function test_cancelling_a_confirmed_order_with_a_shipment_is_forbidden(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner7@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner, quantity: 5, onHand: 50);
        $this->actingAs($owner)->patch("/marlin/orders/{$order->getKey()}/confirm");

        TenantContext::run($this->marlin, function () use ($order): void {
            $location = Location::query()->where('is_default', true)->first();
            Shipment::query()->create([
                'order_id' => $order->getKey(),
                'location_id' => $location->getKey(),
                'carrier' => 'demo',
                'service_level' => 'ground',
                'status' => Shipment::STATUS_LABEL_PENDING,
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/cancel")
            ->assertForbidden();

        TenantContext::run($this->marlin, function () use ($order): void {
            $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        });
    }

    /**
     * Tranziție ilegală: o comandă deja anulată nu mai poate fi anulată din nou.
     * Policy autorizează încercarea (fără shipment), acțiunea refuză STAREA —
     * eroare de validare pe câmp, nu un 403 opac (§7.5, tiparul `MoveDealStageAction`).
     */
    public function test_cancelling_an_already_cancelled_order_is_a_validation_error_not_a_403(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner8@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner);
        $this->actingAs($owner)->patch("/marlin/orders/{$order->getKey()}/cancel");
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$order->getKey()}/cancel")
            ->assertSessionHasErrors('status');
    }

    public function test_confirming_an_order_from_another_tenant_is_not_found(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $stranger = $this->makeMember($cascade, 'stranger@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'owner9@throughput.dev', Permissions::OWNER);
        $order = $this->draftOrderWithLine($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($stranger)
            ->patch("/cascade/orders/{$order->getKey()}/confirm")
            ->assertNotFound();
    }

    private function draftOrderWithLine(User $owner, int $quantity = 1, int $onHand = 100): Order
    {
        $variantId = TenantContext::run($this->marlin, function () use ($onHand): string {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: $onHand);

            return $variant->getKey();
        });

        return TenantContext::run($this->marlin, function () use ($owner, $variantId, $quantity): Order {
            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            $order->orderLines()->create([
                'variant_id' => $variantId,
                'description' => 'Test line',
                'quantity' => $quantity,
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 10 * $quantity,
            ]);

            return $order;
        });
    }
}
