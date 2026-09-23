<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Tests\TestCase;

/**
 * TEST-02 — simetricul lui `ProductUpdateAuthorizationTest`, pe `VariantPolicy::update()`
 * (aceeași permisiune `products.edit` — docblock-ul clasei: variantele n-au permisiuni
 * proprii în catalog, §7.4).
 */
class VariantUpdateAuthorizationTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Product $product;

    private Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->product = TenantContext::run($this->marlin, fn () => (new ProductFactory)->create(['name' => 'Hex Bolts']));
        $this->variant = TenantContext::run($this->marlin, fn () => (new VariantFactory)->create([
            'product_id' => $this->product->getKey(),
            'sku' => 'HEX-BOLT-M8',
        ]));

        $this->clearDatabaseTenantContext();
    }

    public function test_an_owner_can_view_the_edit_page(): void
    {
        $this->actingAs($this->owner)->get("/marlin/variants/{$this->variant->id}/edit")->assertOk();
    }

    public function test_an_owner_can_update_a_variant(): void
    {
        $this->actingAs($this->owner)->put("/marlin/variants/{$this->variant->id}", [
            'sku' => 'HEX-BOLT-M8-V2',
            'price' => 5.5,
            'cost' => 2.5,
        ])->assertRedirect();

        $updated = TenantContext::run($this->marlin, fn () => Variant::query()->findOrFail($this->variant->id));
        $this->assertSame('HEX-BOLT-M8-V2', $updated->sku);
    }

    public function test_a_manager_can_view_the_edit_page(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->get("/marlin/variants/{$this->variant->id}/edit")->assertOk();
    }

    public function test_a_manager_can_update_a_variant(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->put("/marlin/variants/{$this->variant->id}", [
            'sku' => 'HEX-BOLT-M8-MGR',
            'price' => 5.5,
            'cost' => 2.5,
        ])->assertRedirect();

        $updated = TenantContext::run($this->marlin, fn () => Variant::query()->findOrFail($this->variant->id));
        $this->assertSame('HEX-BOLT-M8-MGR', $updated->sku);
    }

    public function test_an_agent_cannot_view_the_edit_page(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get("/marlin/variants/{$this->variant->id}/edit")->assertForbidden();
    }

    public function test_an_agent_cannot_update_a_variant(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->put("/marlin/variants/{$this->variant->id}", [
            'sku' => 'SHOULD-FAIL',
            'price' => 1,
            'cost' => 0.5,
        ])->assertForbidden();

        $this->assertSame('HEX-BOLT-M8', TenantContext::run($this->marlin, fn () => Variant::query()->findOrFail($this->variant->id))->sku);
    }

    public function test_a_viewer_cannot_view_the_edit_page(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->get("/marlin/variants/{$this->variant->id}/edit")->assertForbidden();
    }

    public function test_a_viewer_cannot_update_a_variant(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->put("/marlin/variants/{$this->variant->id}", [
            'sku' => 'SHOULD-FAIL',
            'price' => 1,
            'cost' => 0.5,
        ])->assertForbidden();
    }

    /**
     * Simetric cu `VariantCrudTest::test_sku_uniqueness_does_not_leak_across_tenants()` —
     * dar aici izolarea propriu-zisă (404), nu doar validarea de unicitate.
     */
    public function test_a_variant_from_another_tenant_is_not_found_on_edit_and_update(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeProduct = TenantContext::run($cascade, fn () => (new ProductFactory)->create());
        $foreignVariant = TenantContext::run($cascade, fn () => (new VariantFactory)->create(['product_id' => $cascadeProduct->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/variants/{$foreignVariant->id}/edit")->assertNotFound();

        $this->actingAs($this->owner)->put("/marlin/variants/{$foreignVariant->id}", [
            'sku' => 'CROSS-TENANT',
            'price' => 1,
            'cost' => 0.5,
        ])->assertNotFound();
    }
}
