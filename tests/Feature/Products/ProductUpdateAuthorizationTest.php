<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\ProductFactory;
use Tests\TestCase;

/**
 * TEST-02 (audit 2026-09-23, §11-teste.md) — `ProductPolicy::update()` (permisiunea
 * `products.edit`) gărzuiește 4 rute reale (`products.edit`, `products.update`, plus
 * simetricele lor pe `Variant`, `VariantUpdateAuthorizationTest`), fără NICIUN test de
 * autorizare. `ProductCrudTest` acoperă simetricul pe `create`/`products.create` — aici
 * aceeași formă, pe `update`: Owner/Manager pot, Agent/Viewer nu, fără îngustare ABAC
 * (docblock-ul `ProductPolicy`: un produs n-are proprietar).
 */
class ProductUpdateAuthorizationTest extends TestCase
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

    public function test_an_owner_can_view_the_edit_page(): void
    {
        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}/edit")->assertOk();
    }

    public function test_an_owner_can_update_a_product(): void
    {
        $this->actingAs($this->owner)->put("/marlin/products/{$this->product->id}", [
            'name' => 'Hex Bolts (updated)',
            'unit_of_measure' => 'box',
        ])->assertRedirect();

        $updated = TenantContext::run($this->marlin, fn () => Product::query()->findOrFail($this->product->id));
        $this->assertSame('Hex Bolts (updated)', $updated->name);
    }

    public function test_a_manager_can_view_the_edit_page(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->get("/marlin/products/{$this->product->id}/edit")->assertOk();
    }

    public function test_a_manager_can_update_a_product(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->put("/marlin/products/{$this->product->id}", [
            'name' => 'Washers (updated)',
            'unit_of_measure' => 'each',
        ])->assertRedirect();

        $updated = TenantContext::run($this->marlin, fn () => Product::query()->findOrFail($this->product->id));
        $this->assertSame('Washers (updated)', $updated->name);
    }

    public function test_an_agent_cannot_view_the_edit_page(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get("/marlin/products/{$this->product->id}/edit")->assertForbidden();
    }

    public function test_an_agent_cannot_update_a_product(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->put("/marlin/products/{$this->product->id}", [
            'name' => 'Should be forbidden',
            'unit_of_measure' => 'each',
        ])->assertForbidden();

        $this->assertSame('Hex Bolts', TenantContext::run($this->marlin, fn () => Product::query()->findOrFail($this->product->id))->name);
    }

    public function test_a_viewer_cannot_view_the_edit_page(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->get("/marlin/products/{$this->product->id}/edit")->assertForbidden();
    }

    public function test_a_viewer_cannot_update_a_product(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->put("/marlin/products/{$this->product->id}", [
            'name' => 'Should be forbidden',
            'unit_of_measure' => 'each',
        ])->assertForbidden();
    }

    /**
     * Simetric cu `ProductCrudTest::test_products_are_isolated_per_tenant()`, dar pe
     * rutele de editare: un produs dintr-un ALT tenant nu trebuie găsit, nici pe GET, nici
     * pe PUT — global scope-ul Eloquent (`BelongsToTenant`) face route-binding-ul să dea
     * 404, nu 403 (utilizatorul nici măcar nu vede că rândul există).
     */
    public function test_a_product_from_another_tenant_is_not_found_on_edit_and_update(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $foreignProduct = TenantContext::run($cascade, fn () => (new ProductFactory)->create());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$foreignProduct->id}/edit")->assertNotFound();

        $this->actingAs($this->owner)->put("/marlin/products/{$foreignProduct->id}", [
            'name' => 'Cross-tenant update attempt',
            'unit_of_measure' => 'each',
        ])->assertNotFound();
    }
}
