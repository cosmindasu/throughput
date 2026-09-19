<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\InventoryLevel;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * `CancelOrderAction` — §11.3. RBAC + BR-ORD-01 (blocarea pe shipment) sunt verificate
 * la nivel de Policy/HTTP (`OrderTransitionsHttpTest`); acesta acoperă doar efectul pe
 * stare + `reserved`.
 */
class CancelOrderActionTest extends TestCase
{
    use CreatesOrders;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_cancelling_a_draft_does_not_touch_stock(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 50);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);

            $cancelled = (new CancelOrderAction)->execute($order);

            $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->first();
            $this->assertSame(0, $level->reserved);
        });
    }

    public function test_cancelling_a_confirmed_order_releases_reserved_stock(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 50, reserved: 10);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);

            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->first();
            $this->assertSame(15, $level->reserved);

            $cancelled = (new CancelOrderAction)->execute($confirmed);

            $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
            $level->refresh();
            $this->assertSame(10, $level->reserved, 'Doar cei 5 rezervați de comanda asta se eliberează.');
        });
    }

    /**
     * Code review P1-003 — două linii pe ACEEAȘI variantă, confirmate ca backorder
     * (BR-STOCK-04), eliberează la anulare exact suma lor, prin ACELAȘI rând
     * `inventory_levels` (`LocksInventoryLevels::lockLevelsAtLocation()`), fără să
     * trimită `reserved` sub zero.
     */
    public function test_cancelling_releases_the_sum_of_duplicate_lines_on_the_same_variant(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 10);

            $order = $this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 6],
                ['variant_id' => $variant->getKey(), 'quantity' => 6],
            ]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: true);

            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->first();
            $this->assertSame(12, $level->reserved);

            $cancelled = (new CancelOrderAction)->execute($confirmed);

            $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
            $level->refresh();
            $this->assertSame(0, $level->reserved);
            $this->assertGreaterThanOrEqual(0, $level->reserved, '`reserved` nu coboară sub zero.');
        });
    }

    /**
     * Code review P1 — cursă `CreateShipmentAction` vs. `CancelOrderAction`, reprodusă
     * cu simularea exactă cerută de review: un shipment apare DUPĂ momentul în care
     * `OrderPolicy::cancel()` ar fi citit `shipments()->exists() === false` (citire fără
     * blocare) și ÎNAINTE ca această acțiune să ajungă la blocarea ei. Fără reverificare
     * SUB blocare, BR-ORD-01 s-ar încălca în date — comanda ar deveni `cancelled` cu un
     * shipment `label_pending` agățat.
     */
    public function test_cancelling_is_refused_when_a_shipment_appears_after_the_policy_check_but_before_the_lock(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 50);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);

            // Momentul „citirii" din `OrderPolicy::cancel()` — fără shipment încă.
            $this->assertFalse($confirmed->shipments()->exists());

            // Fereastra cursei: shipment-ul apare ÎNTRE citirea de mai sus (Policy) și
            // blocarea pe care `CancelOrderAction` o ia mai jos.
            Shipment::query()->create([
                'order_id' => $confirmed->getKey(),
                'location_id' => $location->getKey(),
                'carrier' => 'demo',
                'service_level' => 'Ground',
                'status' => Shipment::STATUS_LABEL_PENDING,
            ]);

            try {
                (new CancelOrderAction)->execute($confirmed->fresh());
                $this->fail('Expected a ValidationException — BR-ORD-01.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
                $this->assertSame('This order has a shipment and can no longer be cancelled.', $e->errors()['status'][0]);
            }

            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->first();
            $this->assertSame(5, $level->reserved, '`reserved` rămâne neatins.');
            $this->assertSame(OrderStatus::Confirmed, $confirmed->fresh()->status, 'Comanda rămâne `confirmed`, nu `cancelled`.');
        });
    }

    public function test_cancelling_an_already_cancelled_order_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->draftOrder([]);
            $cancelled = (new CancelOrderAction)->execute($order);

            try {
                (new CancelOrderAction)->execute($cancelled);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_cancelling_a_fulfilled_order_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->draftOrder([]);
            $order->status = OrderStatus::Fulfilled;
            $order->save();

            try {
                (new CancelOrderAction)->execute($order);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $lines
     */
    private function draftOrder(array $lines): Order
    {
        $order = new Order([
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'status' => OrderStatus::Draft,
            'currency' => 'USD',
        ]);
        $order->created_by = $this->owner->getKey();
        $order->save();

        foreach ($lines as $line) {
            $orderLine = new OrderLine([
                'order_id' => $order->getKey(),
                'variant_id' => $line['variant_id'],
                'description' => 'Test line',
                'quantity' => $line['quantity'],
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 10 * $line['quantity'],
            ]);
            $orderLine->save();
        }

        return $order;
    }
}
