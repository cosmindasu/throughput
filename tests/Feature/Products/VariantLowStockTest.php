<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\InventoryLevelFactory;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-STOCK-02 (specs.md §10.7) — pragul de „low stock" pe variantă: validare, contractul
 * de props al `VariantResource` (`lowStockThreshold`/`isLowStock`) și regula exactă
 * (`App\Support\Stock\LowStockRule`): prag NENUL și `available` (suma pe toate locațiile a
 * `on_hand − reserved`, §10.5) STRICT sub el.
 */
class VariantLowStockTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->product = TenantContext::run($this->marlin, fn () => (new ProductFactory)->create(['name' => 'Hex Bolts']));

        $this->clearDatabaseTenantContext();
    }

    public function test_the_threshold_must_be_a_non_negative_integer_when_creating_a_variant(): void
    {
        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'BAD-THRESHOLD',
            'price' => 4.5,
            'cost' => 2.1,
            'low_stock_threshold' => -1,
        ])->assertSessionHasErrors('low_stock_threshold');

        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'BAD-THRESHOLD-2',
            'price' => 4.5,
            'cost' => 2.1,
            'low_stock_threshold' => 'not-a-number',
        ])->assertSessionHasErrors('low_stock_threshold');
    }

    public function test_the_threshold_is_optional_and_defaults_to_no_alert(): void
    {
        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'NO-THRESHOLD',
            'price' => 4.5,
            'cost' => 2.1,
        ])->assertSessionHasNoErrors();

        $variant = TenantContext::run($this->marlin, fn () => Variant::query()->where('sku', 'NO-THRESHOLD')->firstOrFail());
        $this->assertNull($variant->low_stock_threshold);
    }

    public function test_a_manager_can_set_the_threshold_when_updating_a_variant(): void
    {
        $variant = TenantContext::run($this->marlin, fn () => (new VariantFactory)->create(['product_id' => $this->product->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->put("/marlin/variants/{$variant->id}", [
            'sku' => $variant->sku,
            'price' => (float) $variant->price,
            'cost' => (float) $variant->cost,
            'low_stock_threshold' => 15,
        ])->assertRedirect();

        $this->assertSame(15, TenantContext::run($this->marlin, fn () => Variant::query()->whereKey($variant->getKey())->firstOrFail()->low_stock_threshold));
    }

    public function test_the_threshold_is_always_present_on_the_variant_resource_even_when_null(): void
    {
        TenantContext::run($this->marlin, fn () => (new VariantFactory)->create(['product_id' => $this->product->getKey(), 'sku' => 'NO-ALERT']));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('product.variants.0.lowStockThreshold')
                ->where('product.variants.0.lowStockThreshold', null)
            );
    }

    public function test_a_variant_without_a_threshold_is_never_low_stock_regardless_of_available(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $variant = (new VariantFactory)->create(['product_id' => $this->product->getKey(), 'sku' => 'ZERO-STOCK']);
            $location = (new LocationFactory)->create();
            (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 0, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.available', 0)
                ->where('product.variants.0.isLowStock', false)
            );
    }

    /**
     * Regula la limită: `available == prag` NU e „low" — comparația e STRICT `<`, nu `<=`.
     */
    public function test_a_variant_exactly_at_the_threshold_is_not_low_stock(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $variant = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $this->product->getKey(), 'sku' => 'AT-THRESHOLD']);
            $location = (new LocationFactory)->create();
            (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 10, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.available', 10)
                ->where('product.variants.0.lowStockThreshold', 10)
                ->where('product.variants.0.isLowStock', false)
            );
    }

    public function test_a_variant_one_unit_below_the_threshold_is_low_stock(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $variant = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $this->product->getKey(), 'sku' => 'BELOW-THRESHOLD']);
            $location = (new LocationFactory)->create();
            (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 9, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.available', 9)
                ->where('product.variants.0.isLowStock', true)
            );
    }

    /**
     * `available` se agregă pe TOATE locațiile (§10.5) — două rânduri de `inventory_levels`,
     * rezervatul din prima inclus în total, nu ignorat.
     */
    public function test_available_sums_on_hand_and_reserved_across_two_locations(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $variant = (new VariantFactory)->lowStockThreshold(20)->create(['product_id' => $this->product->getKey(), 'sku' => 'TWO-LOCATIONS']);
            $main = (new LocationFactory)->create();
            $overflow = (new LocationFactory)->overflow()->create();

            // Main: 15 on hand, 5 reserved -> available 10.
            (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $main->getKey(), 'on_hand' => 15, 'reserved' => 5]);
            // Overflow: 5 on hand, 0 reserved -> available 5.
            (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $overflow->getKey(), 'on_hand' => 5, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        // Total available = (15 - 5) + (5 - 0) = 15, sub pragul de 20 -> low.
        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.onHand', 20)
                ->where('product.variants.0.reserved', 5)
                ->where('product.variants.0.available', 15)
                ->where('product.variants.0.isLowStock', true)
            );
    }

    public function test_cost_hidden_rows_still_expose_the_low_stock_threshold_to_agent_and_viewer(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, fn () => (new VariantFactory)->lowStockThreshold(5)->create([
            'product_id' => $this->product->getKey(),
            'sku' => 'AGENT-VISIBLE-THRESHOLD',
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->missing('product.variants.0.cost')
                ->where('product.variants.0.lowStockThreshold', 5)
            );
    }
}
