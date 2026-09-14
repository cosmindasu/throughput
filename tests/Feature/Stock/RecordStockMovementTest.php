<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RecordStockMovementAction;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * BR-STOCK-01/02, US-STOCK-01 — recepție și ajustare. `RecordStockMovementAction` scrie
 * mișcarea + proiecția în ACEEAȘI tranzacție; testul de atomicitate verifică asta prin
 * eșec (o ajustare fără notă nu trebuie să lase NICIO urmă, nici mișcare, nici on_hand
 * schimbat), nu doar prin succes.
 */
class RecordStockMovementTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Variant $variant;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        [$this->variant, $this->location] = TenantContext::run($this->marlin, function (): array {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();

            return [$variant, $location];
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_receipt_inserts_a_movement_and_updates_on_hand_in_the_same_transaction(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new RecordStockMovementAction)->execute(
                variant: $this->variant,
                location: $this->location,
                delta: 200,
                reason: StockMovement::REASON_RECEIPT,
                by: $this->owner,
                note: 'PO #4471',
            );

            $level = InventoryLevel::query()
                ->where('variant_id', $this->variant->getKey())
                ->where('location_id', $this->location->getKey())
                ->firstOrFail();

            $this->assertSame(200, $level->on_hand);

            $movement = StockMovement::query()->where('variant_id', $this->variant->getKey())->firstOrFail();
            $this->assertSame(200, $movement->delta);
            $this->assertSame('receipt', $movement->reason);
            $this->assertSame('PO #4471', $movement->note);
            $this->assertSame($this->owner->getKey(), $movement->created_by);
        });
    }

    public function test_a_second_receipt_accumulates_on_hand_instead_of_overwriting_it(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $action = new RecordStockMovementAction;
            $action->execute($this->variant, $this->location, 200, StockMovement::REASON_RECEIPT, $this->owner);
            $action->execute($this->variant, $this->location, 50, StockMovement::REASON_RECEIPT, $this->owner);

            $level = InventoryLevel::query()->where('variant_id', $this->variant->getKey())->firstOrFail();
            $this->assertSame(250, $level->on_hand);
            $this->assertSame(2, StockMovement::query()->where('variant_id', $this->variant->getKey())->count());
        });
    }

    public function test_an_adjustment_without_a_note_is_rejected_and_leaves_no_trace(): void
    {
        TenantContext::run($this->marlin, function (): void {
            try {
                (new RecordStockMovementAction)->execute(
                    variant: $this->variant,
                    location: $this->location,
                    delta: -5,
                    reason: StockMovement::REASON_ADJUSTMENT,
                    by: $this->owner,
                    note: null,
                );
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                // expected
            }

            $this->assertSame(0, StockMovement::query()->count());
            $this->assertFalse(InventoryLevel::query()->where('variant_id', $this->variant->getKey())->exists());
        });
    }

    public function test_stock_movements_are_append_only(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $movement = (new RecordStockMovementAction)->execute(
                $this->variant, $this->location, 10, StockMovement::REASON_RECEIPT, $this->owner
            );

            $this->expectException(RuntimeException::class);
            $movement->update(['delta' => 999]);
        });
    }

    public function test_receiving_stock_via_http_requires_the_stock_adjust_permission(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($agent)->post("/marlin/variants/{$this->variant->id}/stock/receive", [
            'location_id' => $this->location->id,
            'quantity' => 10,
        ])->assertForbidden();

        $this->actingAs($viewer)->post("/marlin/variants/{$this->variant->id}/stock/receive", [
            'location_id' => $this->location->id,
            'quantity' => 10,
        ])->assertForbidden();

        $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/receive", [
            'location_id' => $this->location->id,
            'quantity' => 10,
        ])->assertRedirect();
    }

    public function test_adjusting_stock_via_http_requires_a_note(): void
    {
        $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/adjust", [
            'location_id' => $this->location->id,
            'delta' => -3,
        ])->assertSessionHasErrors('note');
    }

    public function test_a_manager_can_adjust_stock_but_an_agent_cannot(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($manager)->post("/marlin/variants/{$this->variant->id}/stock/adjust", [
            'location_id' => $this->location->id,
            'delta' => 5,
            'note' => 'Cycle count correction.',
        ])->assertRedirect();

        $this->actingAs($agent)->post("/marlin/variants/{$this->variant->id}/stock/adjust", [
            'location_id' => $this->location->id,
            'delta' => 5,
            'note' => 'Should be forbidden.',
        ])->assertForbidden();
    }
}
