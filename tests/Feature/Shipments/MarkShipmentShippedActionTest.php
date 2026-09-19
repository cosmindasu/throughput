<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Actions\Shipments\MarkShipmentShippedAction;
use App\Actions\Stock\RecordStockMovementAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * `MarkShipmentShippedAction` — §11.2 pas 5-6, §11.3. Livrabilul plan §9 literal: o
 * comandă parcurge `draft → confirmed → partially_fulfilled → fulfilled` cu `on_hand`,
 * `reserved` și `quantity_fulfilled` exacte la fiecare pas.
 */
class MarkShipmentShippedActionTest extends TestCase
{
    use CreatesOrders;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    private Location $location;

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
            $this->location = $this->makeDefaultLocation();
        });
    }

    /**
     * Livrabilul plan §9: `draft → confirmed → partially_fulfilled → fulfilled`, cu
     * `on_hand`/`reserved`/`quantity_fulfilled` exacte la fiecare pas.
     */
    public function test_full_lifecycle_with_exact_stock_numbers_at_every_step(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            // `receiveStock()`, NU `setInventory()`: pentru testul de `stock:reconcile` de
            // la finalul acestui test, `on_hand`-ul de bază are nevoie de o mișcare REALĂ
            // în spate (`reason = receipt`) — `setInventory()` scrie doar proiecția, fără
            // ledger, exact divergența pe care reconcilierea există s-o prindă.
            $this->receiveStock($variant, 100);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 10]]);
            $this->assertSame(OrderStatus::Draft, $order->status);

            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
            $line = $confirmed->orderLines()->firstOrFail();

            $level = $this->level($variant);
            $this->assertSame(100, $level->on_hand);
            $this->assertSame(10, $level->reserved);
            $this->assertSame(0, $line->quantity_fulfilled);

            // Shipment 1 — parțial (6 din 10).
            $shipment1 = $this->purchasedShipment($confirmed->fresh(), [$line->getKey() => 6]);
            $shipped1 = (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment1, $this->owner);

            $this->assertSame(Shipment::STATUS_IN_TRANSIT, $shipped1->status);
            $this->assertNotNull($shipped1->shipped_at);

            $level = $this->level($variant);
            $this->assertSame(94, $level->on_hand, '100 − 6.');
            $this->assertSame(4, $level->reserved, '10 − 6.');

            $line->refresh();
            $this->assertSame(6, $line->quantity_fulfilled);

            $order1 = $confirmed->fresh();
            $this->assertSame(OrderStatus::PartiallyFulfilled, $order1->status);

            $movement = StockMovement::query()->where('ref_type', 'shipment')->where('ref_id', $shipment1->getKey())->firstOrFail();
            $this->assertSame(-6, $movement->delta);
            $this->assertSame(StockMovement::REASON_SALE, $movement->reason);

            // Shipment 2 — restul (4 din 10) → fulfilled.
            $shipment2 = $this->purchasedShipment($order1, [$line->getKey() => 4]);
            $shipped2 = (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment2, $this->owner);

            $this->assertSame(Shipment::STATUS_IN_TRANSIT, $shipped2->status);

            $level = $this->level($variant);
            $this->assertSame(90, $level->on_hand, '94 − 4.');
            $this->assertSame(0, $level->reserved, '4 − 4.');

            $line->refresh();
            $this->assertSame(10, $line->quantity_fulfilled);

            $this->assertSame(OrderStatus::Fulfilled, $order1->fresh()->status);
        });

        // FR-STOCK-01 — 0 divergențe după vânzări. AFARA `TenantContext::run()` de mai sus
        // (ca la `StockReconcileTest`): comanda de reconciliere își deschide PROPRIA
        // tranzacție/context per tenant, nu una imbricată în cea a testului.
        $this->assertStockReconciles();
    }

    /**
     * Două linii pe ACEEAȘI variantă, într-un SINGUR shipment: agregate la verificarea
     * `on_hand` și la mișcarea de stoc (o singură mișcare `sale`, cu delta = suma), ca la
     * `ConfirmOrderAction` (P1-002) — nu două mișcări separate.
     */
    public function test_two_lines_on_the_same_variant_in_one_shipment_are_aggregated(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->receiveStock($variant, 50);

            $order = $this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 3],
                ['variant_id' => $variant->getKey(), 'quantity' => 4],
            ]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            [$line1, $line2] = $confirmed->orderLines()->orderBy('id')->get()->all();

            $shipment = $this->purchasedShipment($confirmed, [
                $line1->getKey() => $line1->quantity,
                $line2->getKey() => $line2->quantity,
            ]);

            (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment, $this->owner);

            $level = $this->level($variant);
            $this->assertSame(43, $level->on_hand, '50 − 7 (3+4), o singură mișcare.');
            $this->assertSame(0, $level->reserved);

            $movements = StockMovement::query()->where('ref_type', 'shipment')->where('ref_id', $shipment->getKey())->get();
            $this->assertCount(1, $movements, 'O SINGURĂ mișcare pentru cele două linii pe aceeași variantă.');
            $this->assertSame(-7, $movements->first()->delta);

            $this->assertSame(3, $line1->fresh()->quantity_fulfilled);
            $this->assertSame(4, $line2->fresh()->quantity_fulfilled);

            $this->assertSame(OrderStatus::Fulfilled, $confirmed->fresh()->status);
        });

        $this->assertStockReconciles();
    }

    /**
     * Backorder nerecepționat: `on_hand` insuficient → `ValidationException`, NIMIC scris
     * (nici mișcare, nici `reserved`, nici `quantity_fulfilled`, nici statusul comenzii).
     */
    public function test_insufficient_on_hand_is_refused_with_no_writes(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 3);
            // available = 3, dar comanda cere 10 — backorder confirmat explicit la
            // confirmare (BR-STOCK-04), fizic încă n-a sosit nimic.

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 10]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: true);
            $line = $confirmed->orderLines()->firstOrFail();

            $shipment = $this->purchasedShipment($confirmed, [$line->getKey() => 10]);

            try {
                (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment, $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }

            $level = $this->level($variant);
            $this->assertSame(3, $level->on_hand, 'Neschimbat.');
            $this->assertSame(10, $level->reserved, 'Neschimbat.');

            $line->refresh();
            $this->assertSame(0, $line->quantity_fulfilled);

            $this->assertSame(Shipment::STATUS_LABEL_PURCHASED, $shipment->fresh()->status, 'Shipment-ul rămâne neschimbat.');
            $this->assertSame(OrderStatus::Confirmed, $confirmed->fresh()->status);
            $this->assertSame(0, StockMovement::query()->count(), 'Nicio mișcare scrisă.');
        });
    }

    /**
     * Code review P2-001 — atomicitatea marcării pe DOUĂ variante DIFERITE: prima are
     * `on_hand` suficient, a doua nu. Verificarea completă, peste TOATE variantele,
     * rulează înaintea oricărei scrieri (task brief, item 4) — refuzul pe a doua variantă
     * nu trebuie să lase o mișcare `sale` scrisă pentru prima.
     */
    public function test_atomicity_across_two_different_variants_one_short_refuses_the_whole_shipment(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variantA = $this->makeVariant('HEX-BOLT-M8');
            $variantB = $this->makeVariant('HEX-BOLT-M10');
            $this->setInventory($variantA, $this->location, onHand: 5);
            $this->setInventory($variantB, $this->location, onHand: 2);
            // available(A) = 5 (suficient pentru 5), available(B) = 2 (insuficient pentru 5).

            $order = $this->draftOrder([
                ['variant_id' => $variantA->getKey(), 'quantity' => 5],
                ['variant_id' => $variantB->getKey(), 'quantity' => 5],
            ]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: true);
            [$lineA, $lineB] = $confirmed->orderLines()->orderBy('variant_id')->get()->all();

            $shipment = $this->purchasedShipment($confirmed, [
                $lineA->getKey() => 5,
                $lineB->getKey() => 5,
            ]);

            try {
                (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment, $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }

            $levelA = $this->level($variantA);
            $this->assertSame(5, $levelA->on_hand, 'Variant A neschimbat, deși singură ar fi trecut.');
            $this->assertSame(5, $levelA->reserved, 'Neschimbat.');

            $levelB = $this->level($variantB);
            $this->assertSame(2, $levelB->on_hand, 'Neschimbat.');
            $this->assertSame(5, $levelB->reserved, 'Neschimbat.');

            $lineA->refresh();
            $lineB->refresh();
            $this->assertSame(0, $lineA->quantity_fulfilled, 'Nicio onorare parțială pe prima variantă.');
            $this->assertSame(0, $lineB->quantity_fulfilled);

            $this->assertSame(
                0,
                StockMovement::query()->where('variant_id', $variantA->getKey())->count(),
                'NICIO mișcare `sale` pentru variant A, deși singură ar fi avut stoc suficient.'
            );
            $this->assertSame(0, StockMovement::query()->where('variant_id', $variantB->getKey())->count());

            $this->assertSame(Shipment::STATUS_LABEL_PURCHASED, $shipment->fresh()->status);
            $this->assertSame(OrderStatus::Confirmed, $confirmed->fresh()->status);
        });
    }

    public function test_marking_a_shipment_that_is_not_label_purchased_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 50);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 5]);
            $this->assertSame(Shipment::STATUS_LABEL_PENDING, $shipment->status);

            try {
                (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment, $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    /**
     * Ordinea blocărilor (task brief, item 4): comanda ÎNTÂI, apoi `inventory_levels`
     * într-o SINGURĂ interogare sortată — tiparul deja acceptat de proiect pentru acest
     * gen de dovadă (vezi `ConfirmOrderActionTest::test_confirming_locks_the_tenant_row_with_for_no_key_update()`),
     * nu două conexiuni DB coordonate manual.
     */
    public function test_locks_the_order_before_the_inventory_levels_in_one_sorted_query(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 50);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 5]]);
            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();
            $shipment = $this->purchasedShipment($confirmed, [$line->getKey() => 5]);

            DB::enableQueryLog();
            (new MarkShipmentShippedAction(new RecordStockMovementAction))->execute($shipment, $this->owner);
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            $orderLockIndex = $queries->search(fn (string $sql) => str_contains($sql, 'from "orders"') && str_contains($sql, 'for update'));
            $levelsLockIndex = $queries->search(fn (string $sql) => str_contains($sql, 'from "inventory_levels"') && str_contains($sql, 'for update'));

            $this->assertNotFalse($orderLockIndex, 'Expected a locking query against orders.');
            $this->assertNotFalse($levelsLockIndex, 'Expected a locking query against inventory_levels.');
            $this->assertLessThan($levelsLockIndex, $orderLockIndex, 'Comanda trebuie blocată ÎNAINTEA rândurilor de stoc.');

            $levelsQuery = $queries->get($levelsLockIndex);
            $this->assertStringContainsString('order by', $levelsQuery, 'Blocarea inventory_levels trebuie sortată.');
        });
    }

    private function level(Variant $variant): InventoryLevel
    {
        return InventoryLevel::query()
            ->where('variant_id', $variant->getKey())
            ->where('location_id', $this->location->getKey())
            ->firstOrFail();
    }

    /**
     * BR-STOCK-02/ADR-004 — recepție REALĂ (`RecordStockMovementAction`, `reason = receipt`),
     * nu `setInventory()`: singura formă de `on_hand` de bază pe care `stock:reconcile`
     * o poate confirma la finalul testelor care îl rulează.
     */
    private function receiveStock(Variant $variant, int $quantity): void
    {
        (new RecordStockMovementAction)->execute($variant, $this->location, $quantity, StockMovement::REASON_RECEIPT, $this->owner);
    }

    /**
     * @param  array<string, int>  $quantities
     */
    private function purchasedShipment(Order $order, array $quantities): Shipment
    {
        $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($order, $quantities);
        $shipment->update([
            'status' => Shipment::STATUS_LABEL_PURCHASED,
            'tracking_number' => 'DEMO123',
            'label_url' => 'https://storage.demo.throughput.dev/labels/test.pdf',
        ]);

        return $shipment->fresh();
    }

    private function assertStockReconciles(): void
    {
        $this->clearDatabaseTenantContext();
        $this->artisan('stock:reconcile', ['--tenant' => $this->tenant->slug])->assertExitCode(0);
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
