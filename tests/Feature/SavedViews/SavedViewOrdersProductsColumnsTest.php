<?php

namespace Tests\Feature\SavedViews;

use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Selector de coloane (specs.md §15.1, D2 — cablarea pe Orders/Products după lotul E) —
 * aceleași garanții ca `SavedViewColumnsTest.php` (Accounts/Deals): contractul de props,
 * redirectul spre vederea implicită și round-trip-ul salvare → aplicare, verificate acum
 * pe `orders` (cu ordinea aleasă de utilizator) și pe `products` (cu `lowStock`, coloana
 * implicită nouă din registru).
 */
class SavedViewOrdersProductsColumnsTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_the_default_columns_appear_on_orders_index(): void
    {
        $this->actingAs($this->owner)->get('/marlin/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'columns',
                ['grandTotal', 'placedAt', 'createdAt', 'status', 'account', 'owner']
            ));
    }

    public function test_a_valid_reordered_subset_is_honored_on_orders(): void
    {
        $this->actingAs($this->owner)->get('/marlin/orders?columns=owner,status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status']));
    }

    public function test_an_unknown_column_is_ignored_on_orders_without_a_500(): void
    {
        $this->actingAs($this->owner)->get('/marlin/orders?columns=status,not-a-real-column')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['status']));
    }

    /**
     * `lowStock` e implicită pentru products (D2, decizia proprietarului) — FR-STOCK-02
     * cere alerta vizibilă fără acțiune din partea utilizatorului.
     */
    public function test_the_default_columns_appear_on_products_index_including_low_stock(): void
    {
        $this->actingAs($this->owner)->get('/marlin/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'columns',
                ['category', 'variantsCount', 'isActive', 'lowStock']
            ));
    }

    public function test_a_valid_subset_is_honored_on_products(): void
    {
        $this->actingAs($this->owner)->get('/marlin/products?columns=lowStock,category')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['lowStock', 'category']));
    }

    public function test_an_unknown_column_is_ignored_on_products_without_a_500(): void
    {
        $this->actingAs($this->owner)->get('/marlin/products?columns=category,not-a-real-column')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['category']));
    }

    public function test_opening_orders_with_no_filters_applies_the_saved_default_columns(): void
    {
        $view = $this->createSavedView('orders', ['status' => 'confirmed'], ['status', 'owner'], 'name');

        $this->actingAs($this->owner)
            ->putJson('/marlin/saved-views/orders/default', ['saved_view_id' => $view->getKey()])
            ->assertOk();

        $response = $this->actingAs($this->owner)->get('/marlin/orders');
        $response->assertRedirect();

        $this->actingAs($this->owner)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.filter.status', 'confirmed')
                ->where('columns', ['status', 'owner'])
            );
    }

    public function test_opening_products_with_no_filters_applies_the_saved_default_columns(): void
    {
        $view = $this->createSavedView('products', ['status' => 'active'], ['lowStock', 'variantsCount'], 'name');

        $this->actingAs($this->owner)
            ->putJson('/marlin/saved-views/products/default', ['saved_view_id' => $view->getKey()])
            ->assertOk();

        $response = $this->actingAs($this->owner)->get('/marlin/products');
        $response->assertRedirect();

        $this->actingAs($this->owner)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('list.filter.status', 'active')
                ->where('columns', ['lowStock', 'variantsCount'])
            );
    }

    /**
     * `?columns=` explicit, chiar SINGUR, oprește redirectul spre implicit — la fel ca pe
     * Accounts (`SavedViewColumnsTest`), verificat aici separat pentru `orders`.
     */
    public function test_an_explicit_columns_param_alone_skips_the_orders_default_redirect(): void
    {
        $view = $this->createSavedView('orders', ['status' => 'confirmed'], ['status'], 'name');

        $this->actingAs($this->owner)
            ->putJson('/marlin/saved-views/orders/default', ['saved_view_id' => $view->getKey()])
            ->assertOk();

        $this->actingAs($this->owner)->get('/marlin/orders?columns=owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', ['owner'])
                ->where('filters.filter', [])
            );
    }

    /**
     * Round-trip complet pe orders: salvare cu o ordine ALEASĂ de utilizator → `apply()`
     * scrie exact acea ordine în `?columns=` → pagina țintă o randează la fel.
     */
    public function test_applying_a_saved_orders_view_restores_its_exact_column_order(): void
    {
        $created = $this->actingAs($this->owner)->postJson('/marlin/saved-views', [
            'resource_type' => 'orders',
            'name' => 'Owner then status then total',
            'visibility' => 'private',
            'filter' => [],
            'sort' => '-created_at',
            'columns' => ['owner', 'status', 'grandTotal'],
        ])->assertCreated()->json();

        $this->assertSame(['owner', 'status', 'grandTotal'], $created['columns']);

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$created['id']}/apply");
        $response->assertRedirect();

        $target = (string) $response->headers->get('Location');
        $this->assertStringContainsString('columns=owner%2Cstatus%2CgrandTotal', $target);

        $this->actingAs($this->owner)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status', 'grandTotal']));
    }

    /**
     * Round-trip pe products, cu `lowStock` INCLUSĂ explicit de utilizator alături de o
     * coloană care nu e implicită azi (`createdAt`) — confirmă că selectorul poate produce
     * și o combinație diferită de `defaultColumns()`, nu doar implicitul.
     */
    public function test_applying_a_saved_products_view_restores_low_stock_and_created_at(): void
    {
        $created = $this->actingAs($this->owner)->postJson('/marlin/saved-views', [
            'resource_type' => 'products',
            'name' => 'Low stock and created',
            'visibility' => 'private',
            'filter' => [],
            'sort' => 'name',
            'columns' => ['lowStock', 'createdAt'],
        ])->assertCreated()->json();

        $this->assertSame(['lowStock', 'createdAt'], $created['columns']);

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$created['id']}/apply");
        $response->assertRedirect();

        $this->actingAs($this->owner)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['lowStock', 'createdAt']));
    }

    /**
     * @param  array<string, string>  $filters
     * @param  list<string>  $columns
     */
    private function createSavedView(string $resourceType, array $filters, array $columns, string $sort): SavedView
    {
        return TenantContext::run($this->tenant, function () use ($resourceType, $filters, $columns, $sort): SavedView {
            $view = new SavedView([
                'resource_type' => $resourceType,
                'name' => 'Test view',
                'filters' => $filters,
                'columns' => $columns,
                'sort' => $sort,
                'visibility' => SavedView::VISIBILITY_PRIVATE,
            ]);
            $view->user_id = $this->owner->getKey();
            $view->save();

            return $view;
        });
    }
}
