<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RecordStockMovementAction;
use App\Actions\Stock\TransferStockAction;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BR-STOCK-03, US-STOCK-03 — transfer între locații: două mișcări legate prin același
 * `ref_id`, într-o singură tranzacție.
 */
class TransferStockTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Variant $variant;

    private Location $main;

    private Location $overflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        [$this->variant, $this->main, $this->overflow] = TenantContext::run($this->marlin, function (): array {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $main = (new LocationFactory)->create();
            $overflow = (new LocationFactory)->overflow()->create();

            (new RecordStockMovementAction)->execute($variant, $main, 100, StockMovement::REASON_RECEIPT, $this->owner);

            return [$variant, $main, $overflow];
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_transfer_writes_two_linked_movements_in_one_transaction(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $result = (new TransferStockAction)->execute($this->variant, $this->main, $this->overflow, 30, $this->owner);

            $this->assertSame(-30, $result['from']->delta);
            $this->assertSame(30, $result['to']->delta);
            $this->assertNotEmpty($result['from']->ref_id);
            $this->assertSame($result['from']->ref_id, $result['to']->ref_id);
            $this->assertSame('transfer', $result['from']->reason);
            $this->assertSame('transfer', $result['to']->reason);

            $mainLevel = InventoryLevel::query()->where('variant_id', $this->variant->getKey())->where('location_id', $this->main->getKey())->firstOrFail();
            $overflowLevel = InventoryLevel::query()->where('variant_id', $this->variant->getKey())->where('location_id', $this->overflow->getKey())->firstOrFail();

            $this->assertSame(70, $mainLevel->on_hand);
            $this->assertSame(30, $overflowLevel->on_hand);
        });
    }

    public function test_a_transfer_exceeding_available_stock_at_the_source_is_rejected(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->expectException(ValidationException::class);
            (new TransferStockAction)->execute($this->variant, $this->main, $this->overflow, 1000, $this->owner);
        });

        // Nimic nu trebuie să se fi mișcat — nici măcar parțial.
        TenantContext::run($this->marlin, function (): void {
            $mainLevel = InventoryLevel::query()->where('variant_id', $this->variant->getKey())->where('location_id', $this->main->getKey())->firstOrFail();
            $this->assertSame(100, $mainLevel->on_hand);
            $this->assertSame(2, StockMovement::query()->where('variant_id', $this->variant->getKey())->count());
        });
    }

    public function test_transferring_via_http_requires_the_stock_adjust_permission(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->post("/marlin/variants/{$this->variant->id}/stock/transfer", [
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->overflow->id,
            'quantity' => 10,
        ])->assertForbidden();

        $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/transfer", [
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->overflow->id,
            'quantity' => 10,
        ])->assertRedirect();
    }

    public function test_transferring_more_than_on_hand_via_http_is_rejected_with_a_field_error(): void
    {
        $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/transfer", [
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->overflow->id,
            'quantity' => 1000,
        ])->assertSessionHasErrors('quantity');
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `rules.stock.transfer_available_at_source`,
     * mesajul era literal englez direct în `TransferStockRequest::withValidator()`, cu
     * interpolare `{$var}` în loc de substituent. `:available` trece prin
     * `App\Support\LocaleFormat::count()` — 100, neschimbat (fără zecimale) în ambele limbi.
     */
    public function test_the_transfer_quantity_message_translates_to_french(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $response = $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/transfer", [
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->overflow->id,
            'quantity' => 1000,
        ]);

        $response->assertSessionHasErrors([
            'quantity' => 'Il n’y a que 100 en stock à l’emplacement source.',
        ]);
    }

    public function test_the_same_transfer_quantity_message_stays_english_by_default(): void
    {
        $response = $this->actingAs($this->owner)->post("/marlin/variants/{$this->variant->id}/stock/transfer", [
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->overflow->id,
            'quantity' => 1000,
        ]);

        $response->assertSessionHasErrors([
            'quantity' => 'Only 100 on hand at the source location.',
        ]);
    }
}
