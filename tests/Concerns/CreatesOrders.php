<?php

namespace Tests\Concerns;

use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Variant;

/**
 * Fixture minimă de catalog + stoc pentru testele lotului „Comenzi" (plan §9 task 2-4).
 *
 * Fișier NOU, doar pentru testele acestui pachet — la fel ca `CreatesPipelines`, nu
 * extinde `Tests\TestCase` și nu atinge niciun fișier comun.
 *
 * Apelanții rulează asta ÎN `TenantContext::run()`: `Product`/`Variant`/`Location`/
 * `InventoryLevel` au RLS, iar politica se aplică și la INSERT.
 */
trait CreatesOrders
{
    protected function makeDefaultLocation(string $name = 'Main Warehouse'): Location
    {
        return Location::query()->create(['name' => $name, 'is_default' => true]);
    }

    protected function makeVariant(string $sku = 'HEX-BOLT-M8', float $price = 12.50): Variant
    {
        $product = Product::query()->create(['name' => 'Hex Bolt M8', 'is_active' => true]);

        return Variant::query()->create([
            'product_id' => $product->getKey(),
            'sku' => $sku,
            'attributes' => [],
            'price' => $price,
            'cost' => round($price * 0.6, 2),
            'is_active' => true,
        ]);
    }

    protected function setInventory(Variant $variant, Location $location, int $onHand, int $reserved = 0): InventoryLevel
    {
        return InventoryLevel::query()->create([
            'variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'on_hand' => $onHand,
            'reserved' => $reserved,
        ]);
    }
}
