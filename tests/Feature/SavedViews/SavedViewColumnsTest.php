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
 * Selector de coloane (specs.md §15.1, lotul D din valul 2 al Fazei 3) — parsarea
 * `?columns=` (`App\Support\SavedViews\ListColumns`), contractul de props pe
 * `Accounts/Index`/`Deals/Index`, și round-trip-ul salvare → aplicare (§15.2), la fel ca
 * filtrele/sortarea deja acoperite de `SavedViewApplyTest`.
 */
class SavedViewColumnsTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_the_default_columns_appear_when_the_url_has_none(): void
    {
        $this->actingAs($this->owner)->get('/marlin/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status', 'createdAt']));
    }

    public function test_a_valid_reordered_subset_is_honored_and_the_rest_dropped(): void
    {
        $this->actingAs($this->owner)->get('/marlin/accounts?columns=createdAt,owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['createdAt', 'owner']));
    }

    public function test_an_unknown_column_is_ignored_without_a_500(): void
    {
        $this->actingAs($this->owner)->get('/marlin/accounts?columns=owner,not-a-real-column,status')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status']));
    }

    public function test_an_empty_or_fully_invalid_list_falls_back_to_the_default(): void
    {
        $this->actingAs($this->owner)->get('/marlin/accounts?columns=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status', 'createdAt']));

        $this->actingAs($this->owner)->get('/marlin/accounts?columns=nope,also-nope')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status', 'createdAt']));
    }

    /**
     * Identitatea resursei (`name`) NU e o cheie permisă — a fost exclusă deliberat din
     * `SavedViewResourceType::permittedColumns('accounts')` (rămâne mereu vizibilă, în afara
     * setului configurabil). Un `?columns=name` e deci un nume NECUNOSCUT ca oricare altul.
     */
    public function test_the_identity_column_is_not_a_configurable_key(): void
    {
        $this->actingAs($this->owner)->get('/marlin/accounts?columns=name,owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner']));
    }

    public function test_the_deals_list_has_its_own_default_and_permitted_columns(): void
    {
        $this->actingAs($this->owner)->get('/marlin/deals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'columns',
                ['value', 'expectedCloseDate', 'createdAt', 'account', 'owner', 'stage']
            ));

        $this->actingAs($this->owner)->get('/marlin/deals?columns=stage,owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['stage', 'owner']));
    }

    public function test_saving_a_view_stores_the_current_columns_not_the_default(): void
    {
        $created = $this->actingAs($this->owner)->postJson('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Just created, then updated',
            'visibility' => 'private',
            'filter' => ['status' => 'active'],
            'sort' => 'name',
            'columns' => ['createdAt', 'owner'],
        ])->assertCreated()->json();

        $this->assertSame(['createdAt', 'owner'], $created['columns']);

        TenantContext::run($this->tenant, function () use ($created): void {
            $this->assertSame(['createdAt', 'owner'], SavedView::query()->findOrFail($created['id'])->columns);
        });
    }

    /**
     * Round-trip complet: salvare cu o ordine ALEASĂ de utilizator (nu implicitul resursei)
     * → `apply()` scrie exact acea ordine în `?columns=` → pagina țintă o randează la fel.
     */
    public function test_applying_a_saved_view_restores_its_exact_column_order(): void
    {
        $created = $this->actingAs($this->owner)->postJson('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Created then status then owner',
            'visibility' => 'private',
            'filter' => [],
            'sort' => 'name',
            'columns' => ['createdAt', 'status', 'owner'],
        ])->assertCreated()->json();

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$created['id']}/apply");
        $response->assertRedirect();

        $target = (string) $response->headers->get('Location');
        $this->assertStringContainsString('columns=createdAt%2Cstatus%2Cowner', $target);

        $this->actingAs($this->owner)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['createdAt', 'status', 'owner']));
    }

    /**
     * Specs.md §15.1 — „Un nume necunoscut se ignoră, nu produce 500." O vedere veche (sau
     * scrisă manual, ca aici) cu coloane devenite invalide tot trebuie să deschidă pagina,
     * cu implicitul resursei — la fel ca un filtru devenit invalid (`SavedViewApplyTest`).
     */
    public function test_an_old_view_with_invalid_stored_columns_falls_back_silently(): void
    {
        $view = TenantContext::run($this->tenant, function (): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
                'name' => 'Pre-selector view',
                'filters' => [],
                // Forma veche, dinaintea selectorului (`SavedViewResourceType::defaultColumns()`
                // includea identitatea) — `name` nu mai e o cheie permisă azi.
                'columns' => ['name'],
                'sort' => 'name',
                'visibility' => SavedView::VISIBILITY_PRIVATE,
            ]);
            $view->user_id = $this->owner->getKey();
            $view->save();

            return $view;
        });

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$view->getKey()}/apply");
        $response->assertRedirect();

        $this->actingAs($this->owner)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('columns', ['owner', 'status', 'createdAt']));
    }

    /**
     * P3 (review) — `UpdateSavedViewRequest::rules()` nu declară `columns`, deci
     * `$request->validated()` din `SavedViewController::update()` nu-l include, indiferent
     * ce trimite clientul: redenumirea/schimbarea de vizibilitate NU rescrie coloanele
     * salvate (doar „Save view" o face, cu starea curentă a ecranului). Test de graniță, nu
     * de comportament nou — ca granița să nu se deschidă tăcut la un viitor refactor.
     */
    public function test_patching_a_view_ignores_a_columns_field_in_the_body(): void
    {
        $view = TenantContext::run($this->tenant, function (): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
                'name' => 'Before rename',
                'filters' => [],
                'columns' => ['owner', 'status'],
                'sort' => 'name',
                'visibility' => SavedView::VISIBILITY_PRIVATE,
            ]);
            $view->user_id = $this->owner->getKey();
            $view->save();

            return $view;
        });

        $response = $this->actingAs($this->owner)->patchJson("/marlin/saved-views/{$view->getKey()}", [
            'name' => 'After rename',
            'columns' => ['createdAt'],
        ])->assertOk()->json();

        $this->assertSame('After rename', $response['name']);
        $this->assertSame(['owner', 'status'], $response['columns']);

        TenantContext::run($this->tenant, function () use ($view): void {
            $this->assertSame(['owner', 'status'], $view->fresh()->columns);
        });
    }

    /**
     * `SavedViewDefaultRedirect` — un `?columns=` explicit pe URL, chiar SINGUR (fără
     * `filter`/`sort`), trebuie să oprească redirectul spre implicitul personal, la fel ca
     * `filter`/`sort`/`cursor`: altfel alegerea utilizatorului ar fi suprascrisă tăcut de
     * coloanele vederii implicite.
     */
    public function test_an_explicit_columns_param_alone_skips_the_default_view_redirect(): void
    {
        $view = TenantContext::run($this->tenant, function (): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
                'name' => 'Team default',
                'filters' => ['status' => 'active'],
                'columns' => ['status'],
                'sort' => 'name',
                'visibility' => SavedView::VISIBILITY_TEAM,
            ]);
            $view->user_id = $this->owner->getKey();
            $view->save();

            return $view;
        });

        $this->actingAs($this->owner)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $view->getKey()])
            ->assertOk();

        $this->actingAs($this->owner)->get('/marlin/accounts?columns=owner')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', ['owner'])
                // Filtrul implicit al vederii NU s-a aplicat — redirectul a fost sărit.
                ->where('list.filter', [])
            );
    }
}
