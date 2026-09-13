<?php

namespace Tests\Feature\SavedViews;

use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * FR-TEST-01 (§24.2, ADR-003) — o vedere salvată e o resursă tenant-scoped ca oricare
 * alta: un id din alt tenant trebuie să dea 404 (BOLA, OWASP API1:2023), nu doar un refuz.
 */
class SavedViewTenancyTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $marlinOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->marlinOwner = $this->makeMember($this->marlin, 'owner@marlin.test', Permissions::OWNER);
    }

    public function test_a_saved_view_id_from_another_tenant_is_a_404_on_every_action(): void
    {
        $cascadeOwner = $this->makeMember($this->cascade, 'owner@cascade.test', Permissions::OWNER);
        $cascadeView = $this->createSavedView($this->cascade, $cascadeOwner, SavedView::VISIBILITY_PRIVATE);

        $this->actingAs($this->marlinOwner)->getJson("/marlin/saved-views/{$cascadeView->getKey()}/apply")
            ->assertNotFound();

        $this->actingAs($this->marlinOwner)->patchJson("/marlin/saved-views/{$cascadeView->getKey()}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->actingAs($this->marlinOwner)->deleteJson("/marlin/saved-views/{$cascadeView->getKey()}")
            ->assertNotFound();
    }

    public function test_a_saved_view_id_from_another_tenant_cannot_be_set_as_default(): void
    {
        $cascadeOwner = $this->makeMember($this->cascade, 'owner@cascade.test', Permissions::OWNER);
        $cascadeView = $this->createSavedView($this->cascade, $cascadeOwner, SavedView::VISIBILITY_TEAM);

        $this->actingAs($this->marlinOwner)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $cascadeView->getKey()])
            ->assertNotFound();
    }

    public function test_views_from_another_workspace_are_invisible_in_the_index(): void
    {
        $cascadeOwner = $this->makeMember($this->cascade, 'owner@cascade.test', Permissions::OWNER);
        $this->createSavedView($this->cascade, $cascadeOwner, SavedView::VISIBILITY_TEAM);

        $response = $this->actingAs($this->marlinOwner)->getJson('/marlin/saved-views/accounts')->assertOk()->json();

        $this->assertSame([], $response['mine']);
        $this->assertSame([], $response['team']);
    }

    private function createSavedView(Tenant $tenant, User $user, string $visibility): SavedView
    {
        return TenantContext::run($tenant, function () use ($user, $visibility): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
                'name' => 'Cross-tenant view',
                'filters' => [],
                'columns' => ['name'],
                'sort' => 'name',
                'visibility' => $visibility,
            ]);
            $view->user_id = $user->getKey();
            $view->save();

            return $view;
        });
    }
}
