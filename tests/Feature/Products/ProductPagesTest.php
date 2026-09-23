<?php

namespace Tests\Feature\Products;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\ProductFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TEST-05 — `products.create` și `products.edit` (GET) n-aveau NICIUN test: nici
 * componenta randată, nici props-urile, nici 403 fără permisiune. `ProductCrudTest`
 * acoperă `POST`/`store`, iar `ProductUpdateAuthorizationTest` (TEST-02) acoperă matricea
 * completă de roluri pe `update` — aici doar contractul Inertia al celor două pagini GET,
 * cu un singur caz de refuz per pagină.
 */
class ProductPagesTest extends TestCase
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

    public function test_the_create_page_renders_for_an_owner(): void
    {
        $this->actingAs($this->owner)->get('/marlin/products/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Create'));
    }

    public function test_an_agent_cannot_view_the_create_page(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get('/marlin/products/create')->assertForbidden();
    }

    public function test_the_edit_page_has_the_expected_contract(): void
    {
        $product = TenantContext::run($this->marlin, fn () => (new ProductFactory)->create(['name' => 'Hex Bolts']));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/products/{$product->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Edit')
                ->where('product.id', $product->id)
                ->where('product.name', 'Hex Bolts')
            );
    }

    public function test_a_viewer_cannot_view_the_edit_page(): void
    {
        $product = TenantContext::run($this->marlin, fn () => (new ProductFactory)->create());
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get("/marlin/products/{$product->id}/edit")->assertForbidden();
    }
}
