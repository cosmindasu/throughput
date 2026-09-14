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
use Illuminate\Support\Facades\DB;
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
     * Code review P1-002 — două linii pe ACEEAȘI variantă trebuie agregate ÎNAINTE de
     * comparația cu `available`, nu comparate separat: `on_hand=10`, două linii de 6,
     * niciuna singură nu depășește 10, dar împreună (12) da.
     */
    public function test_two_lines_on_the_same_variant_are_aggregated_before_the_backorder_check(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 10);
            // available = 10, cerere agregată = 12.

            $order = $this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 6],
                ['variant_id' => $variant->getKey(), 'quantity' => 6],
            ]);

            try {
                (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
                $this->fail('Expected a ValidationException for the missing backorder acknowledgement.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('acknowledge_backorder', $e->errors());
            }

            $confirmed = (new ConfirmOrderAction)->execute($order->fresh(), acknowledgeBackorder: true);

            $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
            $level = InventoryLevel::query()->where('variant_id', $variant->getKey())->where('location_id', $location->getKey())->first();
            $this->assertSame(12, $level->reserved, 'Suma celor două linii, nu doar ultima.');
        });
    }

    /**
     * Code review P1-003 — două linii pe o variantă FĂRĂ rând `inventory_levels` încă
     * (nicio proiecție creată de `setInventory()` în acest test): a doua linie nu mai
     * dă `UniqueConstraintViolationException` la `INSERT`, iar rândul creat e UNIC.
     */
    public function test_two_lines_on_a_variant_without_an_inventory_level_row_reserve_the_sum(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            // Nicio `setInventory()` — rândul `inventory_levels` nu există încă.

            $order = $this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 3],
                ['variant_id' => $variant->getKey(), 'quantity' => 4],
            ]);

            // `available` = 0 (niciun rând încă), deci cererea de 7 are nevoie de
            // flagul explicit — BR-STOCK-04, ca la orice altă depășire de stoc.
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: true);

            $this->assertSame(OrderStatus::Confirmed, $confirmed->status);

            $levels = InventoryLevel::query()->where('variant_id', $variant->getKey())->where('location_id', $location->getKey())->get();
            $this->assertCount(1, $levels, 'Un singur rând inventory_levels, creat o dată pentru ambele linii.');
            $this->assertSame(7, $levels->first()->reserved);
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
     * Code review P1-001 — `nextOrderNumber()` blochează `tenants` cu `for no key
     * update`, nu `for update`: verificat direct pe SQL-ul emis (Postgres), nu cu două
     * conexiuni coordonate manual — vezi docblock-ul `nextOrderNumber()` pentru
     * raționamentul complet și tiparul deja acceptat în proiect pentru acest gen de
     * dovadă.
     */
    public function test_confirming_locks_the_tenant_row_with_for_no_key_update(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $location = $this->makeDefaultLocation();
            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 10);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 1]]);

            DB::enableQueryLog();
            (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            $tenantLockQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for '));

            $this->assertTrue($tenantLockQueries->isNotEmpty(), 'Expected a locking query against tenants.');
            $tenantLockQueries->each(fn (string $sql) => $this->assertStringContainsString(
                'for no key update',
                $sql,
                "Expected `for no key update`, got: {$sql}"
            ));
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
