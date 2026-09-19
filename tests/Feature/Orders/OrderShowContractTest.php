<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * Contract de props pe `Orders/Show` — plan §1.2 regula 5, forma nouă din Faza 3 valul 2
 * (§11.2 pas 4): `order.shipments`, `order.lines[].remainingToShip`, `can.createShipment`.
 * Mirror-ul TypeScript trăiește în `resources/js/types/generated.d.ts` — orice schimbare
 * de formă aici se reflectă și acolo (regula clasei din acel fișier).
 */
class OrderShowContractTest extends TestCase
{
    use CreatesOrders;

    private Tenant $marlin;

    private User $owner;

    private Account $account;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
            $this->location = $this->makeDefaultLocation();
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_the_page_has_the_expected_contract_with_a_shipment(): void
    {
        $order = TenantContext::run($this->marlin, function (): Order {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 50);

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => 'draft',
                'currency' => 'USD',
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();
            $order->orderLines()->save(new OrderLine([
                'variant_id' => $variant->getKey(),
                'description' => 'Test line',
                'quantity' => 10,
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 100,
            ]));

            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 4]);

            return $confirmed;
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/orders/{$order->getKey()}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->where('order.id', $order->getKey())
            ->has('order.lines.0.remainingToShip')
            ->where('order.lines.0.remainingToShip', 6)
            ->has('order.shipments', 1)
            ->has('order.shipments.0.id')
            ->has('order.shipments.0.status')
            ->has('order.shipments.0.can.retryLabel')
            ->has('order.shipments.0.can.discard')
            ->has('order.shipments.0.can.markShipped')
            ->has('order.shipments.0.lines', 1)
            ->where('can.createShipment', true)
        );
    }

    public function test_a_shipment_from_another_tenant_never_leaks_into_the_page(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeOwner = $this->makeMember($cascade, 'cascade.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($cascade, function () use ($cascadeOwner): void {
            $account = new Account(['name' => 'Cascade Account']);
            $account->created_by = $cascadeOwner->getKey();
            $account->save();

            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 20);

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $cascadeOwner->getKey(),
                'status' => 'draft',
                'currency' => 'USD',
            ]);
            $order->created_by = $cascadeOwner->getKey();
            $order->save();
            $order->orderLines()->save(new OrderLine([
                'variant_id' => $variant->getKey(),
                'description' => 'Cascade line',
                'quantity' => 3,
                'unit_price' => 5,
                'discount' => 0,
                'line_total' => 15,
            ]));

            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();
            (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 1]);
        });

        $order = TenantContext::run($this->marlin, function (): Order {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 10);

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => 'draft',
                'currency' => 'USD',
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();
            $order->orderLines()->save(new OrderLine([
                'variant_id' => $variant->getKey(),
                'description' => 'Marlin line',
                'quantity' => 2,
                'unit_price' => 5,
                'discount' => 0,
                'line_total' => 10,
            ]));

            return (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/orders/{$order->getKey()}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Orders/Show')
            ->has('order.shipments', 0)
        );

        // Izolare RLS directă: niciun shipment al Cascade nu se poate găsi din contextul
        // Marlin, indiferent de props.
        TenantContext::run($this->marlin, function (): void {
            $this->assertSame(0, Shipment::query()->count());
        });
    }
}
