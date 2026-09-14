<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ConfirmOrderAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\InventoryLevel;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * `ConfirmOrderAction` — §11.2 pas 3, §11.3, BR-ORD-02, BR-STOCK-04. Verificat direct pe
 * acțiune (fără HTTP), la fel ca `MoveDealStageActionTest`: RBAC + izolare de tenant au
 * propriul test HTTP (`OrderTransitionsHttpTest`), acesta acoperă doar regula de business.
 */
class ConfirmOrderActionTest extends TestCase
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

    public function test_confirming_a_draft_reserves_stock_sets_placed_at_and_assigns_a_number(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 100, reserved: 10);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 20]]);

            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);

            $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
            $this->assertNotNull($confirmed->placed_at);
            $this->assertNotNull($confirmed->order_number);
            $this->assertStringEndsWith('-10000', $confirmed->order_number);

            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->where('location_id', $location->getKey())->first();
            $this->assertSame(30, $level->reserved);
            $this->assertSame(100, $level->on_hand, 'confirmarea nu atinge on_hand — §10.5.');
        });
    }

    public function test_confirming_without_lines_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $order = $this->draftOrder([]);

            try {
                (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }

            $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
        });
    }

    public function test_confirming_an_already_confirmed_order_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 50);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);

            try {
                (new ConfirmOrderAction)->execute($confirmed, acknowledgeBackorder: false);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_confirming_over_available_stock_requires_explicit_backorder_acknowledgement(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 10, reserved: 8);
            // available = 2

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);

            try {
                (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
                $this->fail('Expected a ValidationException for the missing backorder acknowledgement.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('acknowledge_backorder', $e->errors());
            }

            // BR-STOCK-04: nici blocare tăcută (testul de mai sus) — nici permitere
            // tăcută. Cu flagul explicit, confirmarea trece și `reserved` depășește
            // `on_hand` (exact ce înseamnă backorder).
            $confirmed = (new ConfirmOrderAction)->execute($order->fresh(), acknowledgeBackorder: true);

            $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->where('location_id', $location->getKey())->first();
            $this->assertSame(13, $level->reserved);
        });
    }

    /**
     * BR-ORD-02 — secvențial per tenant, unic la două confirmări succesive. Proba de
     * concurență e argumentul explicit din docblock-ul `ConfirmOrderAction::nextOrderNumber()`
     * (tiparul deja acceptat în proiect pentru acest gen de dovadă — vezi
     * `MoveDealStageAction`, care documentează identic de ce nu există un test cu două
     * conexiuni DB coordonate manual în jurul unui `lockForUpdate()`).
     */
    public function test_order_numbers_are_sequential_and_unique_per_tenant(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 1000);

            $first = (new ConfirmOrderAction)->execute(
                $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 1]]),
                acknowledgeBackorder: false
            );
            $second = (new ConfirmOrderAction)->execute(
                $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 1]]),
                acknowledgeBackorder: false
            );

            $this->assertNotSame($first->order_number, $second->order_number);
            $this->assertStringEndsWith('-10000', $first->order_number);
            $this->assertStringEndsWith('-10001', $second->order_number);

            // Același prefix pentru amândouă — derivat din slug la prima, reținut din
            // eșantion la a doua.
            $this->assertSame(
                explode('-', $first->order_number)[0],
                explode('-', $second->order_number)[0]
            );
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
