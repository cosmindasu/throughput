<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\StockMovementFactory;
use Database\Factories\VariantFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * §10.2, §7.4 — CRUD pe variante (SKU unic per tenant) + `cost` ascuns din
 * `VariantResource` pentru Agent/Viewer, verificat direct pe propul Inertia (task
 * brief, Pachetul A punctul 1: „nu doar în UI").
 */
class VariantCrudTest extends TestCase
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

    public function test_an_owner_can_create_a_variant(): void
    {
        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'HEX-BOLT-M8',
            'price' => 4.5,
            'cost' => 2.1,
        ])->assertRedirect();

        $variant = TenantContext::run($this->marlin, fn () => Variant::query()->where('sku', 'HEX-BOLT-M8')->firstOrFail());
        $this->assertSame($this->product->getKey(), $variant->product_id);
    }

    public function test_sku_must_be_unique_within_the_tenant(): void
    {
        TenantContext::run($this->marlin, fn () => (new VariantFactory)->create(['product_id' => $this->product->getKey(), 'sku' => 'DUP-1']));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'DUP-1',
            'price' => 1,
            'cost' => 0.5,
        ])->assertSessionHasErrors('sku');
    }

    /**
     * P2-001 pattern — un SKU deja folosit într-un ALT tenant nu blochează validarea:
     * `Rule::unique()` rulează SQL brut, deci tenantul se filtrează explicit în
     * `StoreVariantRequest`.
     */
    public function test_sku_uniqueness_does_not_leak_across_tenants(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeProduct = TenantContext::run($cascade, fn () => (new ProductFactory)->create());
        TenantContext::run($cascade, fn () => (new VariantFactory)->create(['product_id' => $cascadeProduct->getKey(), 'sku' => 'SHARED-SKU']));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'SHARED-SKU',
            'price' => 1,
            'cost' => 0.5,
        ])->assertSessionHasNoErrors();
    }

    public function test_an_agent_cannot_create_a_variant(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->post("/marlin/products/{$this->product->id}/variants", [
            'sku' => 'SHOULD-FAIL',
            'price' => 1,
            'cost' => 0.5,
        ])->assertForbidden();
    }

    /**
     * §7.4 — verificat pe fiecare din cele 4 roluri: Owner și Manager văd `cost`,
     * Agent și Viewer nu — cheia lipsește complet din JSON, nu e `null`.
     */
    public function test_cost_is_visible_only_to_owner_and_manager(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, fn () => (new VariantFactory)->create([
            'product_id' => $this->product->getKey(),
            'sku' => 'COST-TEST',
            'cost' => 9.99,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->has('product.variants.0.cost'));

        $this->actingAs($manager)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->has('product.variants.0.cost'));

        $this->actingAs($agent)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('product.variants.0.cost'));

        $this->actingAs($viewer)->get("/marlin/products/{$this->product->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('product.variants.0.cost'));
    }

    public function test_a_variant_with_stock_movements_cannot_be_deleted(): void
    {
        $variant = TenantContext::run($this->marlin, function (): Variant {
            $variant = (new VariantFactory)->create(['product_id' => $this->product->getKey()]);
            $location = (new LocationFactory)->create();
            $movement = (new StockMovementFactory)->make(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey()]);
            $movement->created_by = $this->owner->getKey();
            $movement->save();

            return $variant;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/variants/{$variant->id}")->assertRedirect();

        $this->assertTrue(TenantContext::run($this->marlin, fn () => Variant::query()->whereKey($variant->getKey())->exists()));
    }
}
