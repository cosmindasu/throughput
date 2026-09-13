<?php

namespace Tests\Feature\SavedViews;

use App\Models\Account;
use App\Models\Deal;
use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * FR-VIEW-01, US-VIEW-01/02 — „aplicarea" unei vederi salvate e un `GET` obișnuit
 * (specs.md §15.2, URL-ul rămâne sursa de adevăr), care reface filtrele/sortarea prin
 * `ResourceList::fromState()` — aceeași definiție de listă care validează cererile HTTP
 * normale, nu o rescriere brută a query string-ului stocat.
 */
class SavedViewApplyTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_applying_a_view_redirects_with_its_exact_filters_and_sort(): void
    {
        $view = $this->createSavedView($this->owner, 'accounts', ['status' => 'active'], '-created_at', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$view->getKey()}/apply");
        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->actingAs($this->owner)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('list.filter.status', 'active')
                ->where('list.sort', '-created_at')
            );
    }

    /**
     * US-VIEW-01 — o vedere „All accounts" salvată de un Agent trebuie să câștige în fața
     * implicitului de rol („My accounts", `AccountList::defaultFilters()`), altfel vederea
     * salvată n-ar fi de încredere exact pentru rolul pentru care US-VIEW-01 a fost scrisă.
     */
    public function test_applying_a_view_overrides_the_agents_my_accounts_default(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);
        $view = $this->createSavedView($agent, 'accounts', ['owner' => 'all'], 'name', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($agent)->get("/marlin/saved-views/{$view->getKey()}/apply");
        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->actingAs($agent)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('list.filter.owner', 'all'));
    }

    /**
     * Un filtru devenit invalid între timp (valoare de `status` care nu mai există în
     * `AccountList::STATUSES`) dispare tăcut la aplicare — `ResourceList::fromState()` nu
     * aruncă 422 pentru un snapshot vechi, la fel ca un link partajat vechi (specs.md §15.2).
     */
    public function test_an_invalid_stored_filter_is_dropped_silently_on_apply(): void
    {
        $view = $this->createSavedView($this->owner, 'accounts', ['status' => 'archived-legacy-value'], 'name', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$view->getKey()}/apply");
        $response->assertRedirect();

        $target = $response->headers->get('Location');
        $this->assertStringNotContainsString('status', (string) $target);

        $this->actingAs($this->owner)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('list.filter', []));
    }

    /**
     * Registrul `SavedViewResourceType` acoperă și `deals` — `DealList` are propria
     * suprascriere de `query()` (sortare pe `value_sort`, nu `value`), verificată aici
     * separat de `accounts`.
     */
    public function test_a_deals_view_applies_through_the_deal_list_definition(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $pipeline = $this->makeDefaultPipeline($this->tenant);
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline['pipeline']->getKey(),
                'stage_id' => $pipeline['stages']['New']->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Deal in scope',
                'value' => 500,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();
        });

        $view = $this->createSavedView($this->owner, 'deals', ['status' => 'open'], '-value', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($this->owner)->get("/marlin/saved-views/{$view->getKey()}/apply");
        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->actingAs($this->owner)->get($target)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.filter.status', 'open')
                ->where('filters.sort', '-value')
            );
    }

    private function createSavedView(User $user, string $resourceType, array $filters, string $sort, string $visibility): SavedView
    {
        return TenantContext::run($this->tenant, function () use ($user, $resourceType, $filters, $sort, $visibility): SavedView {
            $view = new SavedView([
                'resource_type' => $resourceType,
                'name' => 'Test view',
                'filters' => $filters,
                'columns' => ['name'],
                'sort' => $sort,
                'visibility' => $visibility,
            ]);
            $view->user_id = $user->getKey();
            $view->save();

            return $view;
        });
    }
}
