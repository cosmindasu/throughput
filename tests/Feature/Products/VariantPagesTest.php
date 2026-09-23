<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TEST-05 — `variants.create` și `variants.edit` (GET) n-aveau NICIUN test. Simetric cu
 * `ProductPagesTest`: doar contractul Inertia + un 403 minimal per pagină; matricea
 * completă de roluri e în `VariantUpdateAuthorizationTest` (TEST-02, pe `update`) și
 * `VariantCrudTest` (pe `store`).
 */
class VariantPagesTest extends TestCase
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

    public function test_the_create_page_has_the_expected_contract(): void
    {
        $this->actingAs($this->owner)->get("/marlin/products/{$this->product->id}/variants/create")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Variants/Create')
                ->where('product.id', $this->product->id)
                ->where('product.name', 'Hex Bolts')
            );
    }

    public function test_an_agent_cannot_view_the_create_page(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get("/marlin/products/{$this->product->id}/variants/create")->assertForbidden();
    }

    public function test_the_edit_page_has_the_expected_contract(): void
    {
        $variant = TenantContext::run($this->marlin, fn () => (new VariantFactory)->create([
            'product_id' => $this->product->getKey(),
            'sku' => 'HEX-BOLT-M8',
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/variants/{$variant->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Variants/Edit')
                ->where('variant.id', $variant->id)
                ->where('variant.sku', 'HEX-BOLT-M8')
                ->where('product.id', $this->product->id)
                ->where('product.name', 'Hex Bolts')
            );
    }

    public function test_a_viewer_cannot_view_the_edit_page(): void
    {
        $variant = TenantContext::run($this->marlin, fn () => (new VariantFactory)->create(['product_id' => $this->product->getKey()]));
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get("/marlin/variants/{$variant->id}/edit")->assertForbidden();
    }
}
