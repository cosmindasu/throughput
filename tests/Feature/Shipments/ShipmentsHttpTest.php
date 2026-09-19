<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * Onorare/expediere prin HTTP — RBAC pe cele patru roluri (§7.4, rândul „Onorare /
 * expediere"), izolare de tenant, `{order}`/`{shipment}` care nu se potrivesc → 404.
 */
class ShipmentsHttpTest extends TestCase
{
    use CreatesOrders;

    private Tenant $marlin;

    private Account $account;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'owner0@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
            $this->location = $this->makeDefaultLocation();
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_viewer_cannot_create_a_shipment(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $order = $this->confirmedOrderWithLine();
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->post("/marlin/orders/{$order->getKey()}/shipments", ['lines' => [$this->firstLineId($order) => 1]])
            ->assertForbidden();
    }

    public function test_an_agent_cannot_create_a_shipment_on_an_order_they_do_not_own(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent1@throughput.dev', Permissions::AGENT);
        $strangerOwner = $this->makeMember($this->marlin, 'owner1@throughput.dev', Permissions::OWNER);
        $order = $this->confirmedOrderWithLine($strangerOwner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->post("/marlin/orders/{$order->getKey()}/shipments", ['lines' => [$this->firstLineId($order) => 1]])
            ->assertForbidden();
    }

    public function test_an_agent_can_create_a_shipment_on_their_own_order(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent2@throughput.dev', Permissions::AGENT);
        $order = $this->confirmedOrderWithLine($agent);
        $lineId = $this->firstLineId($order);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->post("/marlin/orders/{$order->getKey()}/shipments", ['lines' => [$lineId => 2]])
            ->assertRedirect("/marlin/orders/{$order->getKey()}");

        TenantContext::run($this->marlin, function () use ($order): void {
            $this->assertSame(1, $order->fresh()->shipments()->count());
        });
    }

    /**
     * §7.4 — Agent are „CU*", nu „D": nu poate renunța nici la propriul shipment eșuat.
     */
    public function test_an_agent_cannot_discard_their_own_failed_shipment(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent3@throughput.dev', Permissions::AGENT);
        $shipment = $this->failedShipmentFor($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->delete("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}")
            ->assertForbidden();
    }

    public function test_a_manager_can_discard_a_failed_shipment_on_any_order(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent4@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'manager1@throughput.dev', Permissions::MANAGER);
        $shipment = $this->failedShipmentFor($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($manager)
            ->delete("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}")
            ->assertRedirect("/marlin/orders/{$shipment->order_id}");

        TenantContext::run($this->marlin, function () use ($shipment): void {
            $this->assertNull(Shipment::query()->find($shipment->getKey()));
        });
    }

    public function test_an_agent_can_retry_their_own_failed_shipment(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent5@throughput.dev', Permissions::AGENT);
        $shipment = $this->failedShipmentFor($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/retry")
            ->assertRedirect("/marlin/orders/{$shipment->order_id}");

        TenantContext::run($this->marlin, function () use ($shipment): void {
            $this->assertSame(Shipment::STATUS_LABEL_PENDING, $shipment->fresh()->status);
        });
    }

    /**
     * `{order}`/`{shipment}` se rezolvă independent — un shipment al altei comenzi
     * (același tenant) prin URL-ul greșit dă 404, nu se acționează asupra lui.
     */
    public function test_a_shipment_that_does_not_belong_to_the_order_in_the_url_is_not_found(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner6@throughput.dev', Permissions::OWNER);
        $orderA = $this->confirmedOrderWithLine($owner);
        $shipmentB = $this->failedShipmentFor($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$orderA->getKey()}/shipments/{$shipmentB->getKey()}/retry")
            ->assertNotFound();
    }

    public function test_tenant_isolation_a_shipment_from_another_tenant_is_not_found(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $stranger = $this->makeMember($cascade, 'stranger@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'owner7@throughput.dev', Permissions::OWNER);
        $shipment = $this->failedShipmentFor($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($stranger)
            ->patch("/cascade/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/retry")
            ->assertNotFound();
    }

    /**
     * Code review P2 — „Mark as shipped" (`orders.shipments.ship`) n-avea niciun test
     * HTTP. Aceeași acoperire ca la create/retry/discard.
     */
    public function test_a_viewer_cannot_mark_a_shipment_as_shipped(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer2@throughput.dev', Permissions::VIEWER);
        $owner = $this->makeMember($this->marlin, 'owner8@throughput.dev', Permissions::OWNER);
        $shipment = $this->purchasedShipmentFor($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->patch("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/ship")
            ->assertForbidden();
    }

    public function test_an_agent_cannot_mark_as_shipped_on_an_order_they_do_not_own(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent6@throughput.dev', Permissions::AGENT);
        $strangerOwner = $this->makeMember($this->marlin, 'owner9@throughput.dev', Permissions::OWNER);
        $shipment = $this->purchasedShipmentFor($strangerOwner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/ship")
            ->assertForbidden();

        TenantContext::run($this->marlin, function () use ($shipment): void {
            $this->assertSame(Shipment::STATUS_LABEL_PURCHASED, $shipment->fresh()->status, 'Neschimbat.');
        });
    }

    public function test_an_agent_can_mark_as_shipped_on_their_own_order(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent7@throughput.dev', Permissions::AGENT);
        $shipment = $this->purchasedShipmentFor($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/ship")
            ->assertRedirect("/marlin/orders/{$shipment->order_id}");

        TenantContext::run($this->marlin, function () use ($shipment): void {
            $this->assertSame(Shipment::STATUS_IN_TRANSIT, $shipment->fresh()->status);
        });
    }

    public function test_a_manager_can_mark_any_shipment_as_shipped(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent8@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'manager2@throughput.dev', Permissions::MANAGER);
        $shipment = $this->purchasedShipmentFor($agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($manager)
            ->patch("/marlin/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/ship")
            ->assertRedirect("/marlin/orders/{$shipment->order_id}");

        TenantContext::run($this->marlin, function () use ($shipment): void {
            $this->assertSame(Shipment::STATUS_IN_TRANSIT, $shipment->fresh()->status);
        });
    }

    public function test_marking_as_shipped_a_shipment_that_does_not_belong_to_the_order_in_the_url_is_not_found(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner10@throughput.dev', Permissions::OWNER);
        $orderA = $this->confirmedOrderWithLine($owner);
        $shipmentB = $this->purchasedShipmentFor($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/orders/{$orderA->getKey()}/shipments/{$shipmentB->getKey()}/ship")
            ->assertNotFound();
    }

    public function test_tenant_isolation_marking_as_shipped_a_shipment_from_another_tenant_is_not_found(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $stranger = $this->makeMember($cascade, 'stranger2@throughput.dev', Permissions::OWNER);

        $owner = $this->makeMember($this->marlin, 'owner11@throughput.dev', Permissions::OWNER);
        $shipment = $this->purchasedShipmentFor($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($stranger)
            ->patch("/cascade/orders/{$shipment->order_id}/shipments/{$shipment->getKey()}/ship")
            ->assertNotFound();
    }

    private function confirmedOrderWithLine(?User $owner = null): Order
    {
        $owner ??= $this->makeMember($this->marlin, 'owner-fallback-'.uniqid().'@throughput.dev', Permissions::OWNER);

        return TenantContext::run($this->marlin, function () use ($owner): Order {
            $variant = $this->makeVariant('SKU-'.uniqid());
            $this->setInventory($variant, $this->location, onHand: 50);

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            $order->orderLines()->save(new OrderLine([
                'variant_id' => $variant->getKey(),
                'description' => 'Test line',
                'quantity' => 5,
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 50,
            ]));

            return (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
        });
    }

    private function firstLineId(Order $order): string
    {
        return TenantContext::run($this->marlin, fn () => $order->fresh()->orderLines()->firstOrFail()->getKey());
    }

    private function failedShipmentFor(User $owner): Shipment
    {
        return TenantContext::run($this->marlin, function () use ($owner): Shipment {
            $order = $this->confirmedOrderWithLine($owner);
            $line = $order->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($order, [$line->getKey() => 2]);
            $shipment->update(['status' => Shipment::STATUS_LABEL_FAILED, 'error_message' => 'Destination address failed validation.']);

            return $shipment->fresh();
        });
    }

    private function purchasedShipmentFor(User $owner): Shipment
    {
        return TenantContext::run($this->marlin, function () use ($owner): Shipment {
            $order = $this->confirmedOrderWithLine($owner);
            $line = $order->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($order, [$line->getKey() => 2]);
            $shipment->update([
                'status' => Shipment::STATUS_LABEL_PURCHASED,
                'tracking_number' => 'DEMO123',
                'label_url' => 'https://storage.demo.throughput.dev/labels/test.pdf',
            ]);

            return $shipment->fresh();
        });
    }
}
