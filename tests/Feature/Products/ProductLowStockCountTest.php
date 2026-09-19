<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\InventoryLevelFactory;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-STOCK-02 (task brief, punctul 4) — `lowStockVariantsCount` pe rândul de produs din
 * `Products/Index`: calculat printr-o subinterogare agregată în `ProductList::baseQuery()`
 * (`App\Support\Stock\LowStockRule::lowVariantsCountSubquery()`), NU printr-un `withCount`
 * separat sau o buclă PHP peste variante — numărul de interogări trebuie să rămână
 * constant indiferent de câte produse sunt pe pagină.
 */
class ProductLowStockCountTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_the_product_resource_exposes_the_low_stock_variants_count(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $product = (new ProductFactory)->create(['name' => 'Counted Product']);
            $location = (new LocationFactory)->create();

            // Variantă „low": prag 10, available 4.
            $low = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $product->getKey(), 'sku' => 'LOW-1']);
            (new InventoryLevelFactory)->create(['variant_id' => $low->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 4, 'reserved' => 0]);

            // Variantă cu prag, dar stoc sănătos — nu se numără.
            $healthy = (new VariantFactory)->lowStockThreshold(5)->create(['product_id' => $product->getKey(), 'sku' => 'HEALTHY-1']);
            (new InventoryLevelFactory)->create(['variant_id' => $healthy->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 50, 'reserved' => 0]);

            // Variantă fără prag — niciodată „low", indiferent de stoc.
            $noThreshold = (new VariantFactory)->create(['product_id' => $product->getKey(), 'sku' => 'NO-THRESHOLD-1']);
            (new InventoryLevelFactory)->create(['variant_id' => $noThreshold->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 0, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        $version = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Products/Index',
                'X-Inertia-Partial-Data' => 'products',
            ])
            ->get('/marlin/products')
            ->headers->get('x-inertia-version');

        // `products` e un prop DEFERRED (`Inertia::defer`, FR-PERF-01): pe un reload
        // parțial, Inertia răspunde JSON brut, nu view-ul Blade cu `page` — `assertInertia()`
        // caută `assertViewHas('page')` (vezi `AssertableInertia::fromTestResponse()`), deci
        // aici se citește direct JSON-ul răspunsului, la fel ca la orice cerere XHR.
        $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Products/Index',
                'X-Inertia-Partial-Data' => 'products',
            ])
            ->get('/marlin/products')
            ->assertOk()
            ->assertJsonPath('props.products.data.0.variantsCount', 3)
            ->assertJsonPath('props.products.data.0.lowStockVariantsCount', 1);
    }

    /**
     * P3, code review — cele două forme ale regulii (`LowStockRule::isLow()` pe
     * `Products/Show`, `lowVariantsCountSubquery()` pe `Products/Index`) trebuie să cadă de
     * acord pe exact aceleași trei variante: la limită (`available == threshold`, nu e low),
     * cu un pas sub prag (`available == threshold - 1`, e low) și inactivă sub prag (nu e
     * low — decizie de produs: o variantă inactivă nu se mai recomandă la aprovizionare).
     */
    public function test_the_variant_resource_and_the_product_list_count_agree_on_the_same_three_variants(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $product = (new ProductFactory)->create(['name' => 'Agreement Product']);
            $location = (new LocationFactory)->create();

            // La limită: available (10) == threshold (10) -> NU e low.
            $atThreshold = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $product->getKey(), 'sku' => 'AT-THRESHOLD']);
            (new InventoryLevelFactory)->create(['variant_id' => $atThreshold->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 10, 'reserved' => 0]);

            // Un pas sub prag: available (9) < threshold (10) -> e low.
            $belowThreshold = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $product->getKey(), 'sku' => 'BELOW-THRESHOLD']);
            (new InventoryLevelFactory)->create(['variant_id' => $belowThreshold->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 9, 'reserved' => 0]);

            // Inactivă, mult sub prag: available (2) < threshold (10), dar is_active=false -> NU e low.
            $inactiveBelow = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $product->getKey(), 'sku' => 'INACTIVE-BELOW', 'is_active' => false]);
            (new InventoryLevelFactory)->create(['variant_id' => $inactiveBelow->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 2, 'reserved' => 0]);
        });
        $this->clearDatabaseTenantContext();

        // Forma PHP — `Products/Show` (`VariantResource::isLowStock`), variantele ordonate
        // pe `sku` de `ProductController::show()`: AT- < BELOW- < INACTIVE-.
        $product = TenantContext::run($this->marlin, fn () => Product::query()->where('name', 'Agreement Product')->firstOrFail());

        $this->actingAs($this->owner)->get("/marlin/products/{$product->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.variants.0.sku', 'AT-THRESHOLD')
                ->where('product.variants.0.isLowStock', false)
                ->where('product.variants.1.sku', 'BELOW-THRESHOLD')
                ->where('product.variants.1.isLowStock', true)
                ->where('product.variants.2.sku', 'INACTIVE-BELOW')
                ->where('product.variants.2.isLowStock', false)
            );

        // Forma SQL — `Products/Index` (`lowStockVariantsCount`): o singură variantă activă
        // e efectiv sub prag, deci 1, nu 2 — deși DOUĂ variante au `available < threshold`.
        $version = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Products/Index',
                'X-Inertia-Partial-Data' => 'products',
            ])
            ->get('/marlin/products')
            ->headers->get('x-inertia-version');

        $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'Products/Index',
                'X-Inertia-Partial-Data' => 'products',
            ])
            ->get('/marlin/products')
            ->assertOk()
            ->assertJsonPath('props.products.data.0.variantsCount', 3)
            ->assertJsonPath('props.products.data.0.lowStockVariantsCount', 1);
    }

    /**
     * Aceeași tehnică ca `OrderCrudHttpTest::test_the_orders_list_does_not_n_plus_one_...()`:
     * numărul de interogări la reload parțial nu trebuie să crească proporțional cu numărul
     * de produse din pagină.
     */
    public function test_the_products_list_does_not_n_plus_one_the_low_stock_count_per_row(): void
    {
        $version = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => 'warmup',
                'X-Inertia-Partial-Component' => 'Products/Index',
                'X-Inertia-Partial-Data' => 'products',
            ])
            ->get('/marlin/products')
            ->headers->get('x-inertia-version');

        $queryCountFor = function (int $productCount) use ($version): int {
            TenantContext::run($this->marlin, function () use ($productCount): void {
                Product::query()->delete();
                $location = (new LocationFactory)->create();

                for ($i = 0; $i < $productCount; $i++) {
                    $product = (new ProductFactory)->create();
                    $variant = (new VariantFactory)->lowStockThreshold(10)->create(['product_id' => $product->getKey()]);
                    (new InventoryLevelFactory)->create(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey(), 'on_hand' => 3, 'reserved' => 0]);
                }
            });
            $this->clearDatabaseTenantContext();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)
                ->withHeaders([
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => $version,
                    'X-Inertia-Partial-Component' => 'Products/Index',
                    'X-Inertia-Partial-Data' => 'products',
                ])
                ->get('/marlin/products')
                ->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $withOneProduct = $queryCountFor(1);
        $withTwentyProducts = $queryCountFor(20);

        $this->assertSame(
            $withOneProduct,
            $withTwentyProducts,
            "Query count should stay constant regardless of product count; got {$withOneProduct} for 1 product and {$withTwentyProducts} for 20."
        );
    }
}
