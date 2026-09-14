<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\StockMovementFactory;
use Database\Factories\VariantFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * §10.2, §7.4 — CRUD pe produse: Owner/Manager au acces complet, Agent/Viewer doar
 * citire. Fără îngustare ABAC (spre deosebire de Accounts): un produs n-are proprietar.
 */
class ProductCrudTest extends TestCase
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

    public function test_an_owner_can_create_a_product(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/products', [
            'name' => 'Hex Bolts',
            'category' => 'fasteners',
            'unit_of_measure' => 'box',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $product = TenantContext::run($this->marlin, fn () => Product::query()->where('name', 'Hex Bolts')->firstOrFail());
        $this->assertSame('fasteners', $product->category);
    }

    public function test_a_manager_can_create_a_product(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->post('/marlin/products', [
            'name' => 'Washers',
            'unit_of_measure' => 'each',
        ])->assertRedirect();

        $this->assertTrue(TenantContext::run($this->marlin, fn () => Product::query()->where('name', 'Washers')->exists()));
    }

    public function test_an_agent_cannot_create_a_product(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->post('/marlin/products', [
            'name' => 'Should be forbidden',
            'unit_of_measure' => 'each',
        ])->assertForbidden();
    }

    public function test_a_viewer_cannot_create_a_product(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->post('/marlin/products', [
            'name' => 'Should be forbidden',
            'unit_of_measure' => 'each',
        ])->assertForbidden();
    }

    public function test_the_index_page_hides_write_actions_from_agent_and_viewer(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($this->owner)->get('/marlin/products')
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true));

        $this->actingAs($agent)->get('/marlin/products')
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false));

        $this->actingAs($viewer)->get('/marlin/products')
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false));
    }

    public function test_a_product_with_stock_history_on_a_variant_cannot_be_deleted(): void
    {
        $product = TenantContext::run($this->marlin, function (): Product {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();
            $movement = (new StockMovementFactory)->make(['variant_id' => $variant->getKey(), 'location_id' => $location->getKey()]);
            $movement->created_by = $this->owner->getKey();
            $movement->save();

            return $product;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/products/{$product->id}")->assertRedirect();

        $this->assertTrue(
            TenantContext::run($this->marlin, fn () => Product::query()->whereKey($product->getKey())->exists()),
            'A product with stock history on a variant must not be deleted — its ledger would orphan.'
        );
    }

    public function test_a_product_without_history_can_be_deleted(): void
    {
        $product = TenantContext::run($this->marlin, fn () => (new ProductFactory)->create());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/products/{$product->id}")->assertRedirect();

        $this->assertFalse(TenantContext::run($this->marlin, fn () => Product::query()->whereKey($product->getKey())->exists()));
    }

    public function test_products_are_isolated_per_tenant(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeOwner = $this->makeMember($cascade, 'demo.owner@cascade.dev', Permissions::OWNER);
        TenantContext::run($cascade, fn () => (new ProductFactory)->create(['name' => 'Cascade-only product']));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/products')->assertOk();

        $this->assertFalse(
            TenantContext::run($this->marlin, fn () => Product::query()->where('name', 'Cascade-only product')->exists()),
            'A product created under another tenant must never be visible under this one.'
        );
    }
}
