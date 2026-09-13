<?php

namespace Tests\Feature\SavedViews;

use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Matricea §7.4 — „Vizualizări salvate — private": CRUD pentru toate cele 4 roluri.
 * „Vizualizări salvate — echipă": CRUD pentru Owner/Manager, doar `R` pentru Agent/Viewer.
 * `SavedViewPolicy` traduce asta în `manage_own` (toți) + `manage_team` (doar Owner/Manager).
 */
class SavedViewRbacTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
    }

    public function test_every_role_can_create_rename_and_delete_their_own_private_view(): void
    {
        foreach ([Permissions::OWNER, Permissions::MANAGER, Permissions::AGENT, Permissions::VIEWER] as $role) {
            $user = $this->makeMember($this->tenant, mb_strtolower($role).'@throughput.dev', $role);

            $created = $this->actingAs($user)->postJson('/marlin/saved-views', [
                'resource_type' => 'accounts',
                'name' => "{$role} private view",
                'visibility' => 'private',
                'filter' => ['status' => 'active'],
                'sort' => 'name',
            ])->assertCreated()->json();

            $this->actingAs($user)->patchJson("/marlin/saved-views/{$created['id']}", ['name' => 'Renamed'])
                ->assertOk()
                ->assertJsonPath('name', 'Renamed');

            $this->actingAs($user)->deleteJson("/marlin/saved-views/{$created['id']}")->assertNoContent();
        }
    }

    public function test_an_agent_cannot_create_a_team_view(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->postJson('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Team attempt',
            'visibility' => 'team',
        ])->assertForbidden();
    }

    public function test_a_viewer_cannot_create_a_team_view(): void
    {
        $viewer = $this->makeMember($this->tenant, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->postJson('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Team attempt',
            'visibility' => 'team',
        ])->assertForbidden();
    }

    public function test_a_manager_can_create_rename_and_delete_a_team_view(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);

        $created = $this->actingAs($manager)->postJson('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Urgent accounts',
            'visibility' => 'team',
        ])->assertCreated()->json();

        $this->actingAs($manager)->patchJson("/marlin/saved-views/{$created['id']}", ['name' => 'Urgent accounts (renamed)'])
            ->assertOk()
            ->assertJsonPath('name', 'Urgent accounts (renamed)');

        $this->actingAs($manager)->deleteJson("/marlin/saved-views/{$created['id']}")->assertNoContent();
    }

    public function test_an_agent_cannot_rename_or_delete_a_team_view(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        $teamView = $this->createSavedView($manager, 'Team view', SavedView::VISIBILITY_TEAM);

        $this->actingAs($agent)->patchJson("/marlin/saved-views/{$teamView->getKey()}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($agent)->deleteJson("/marlin/saved-views/{$teamView->getKey()}")
            ->assertForbidden();
    }

    public function test_an_agent_cannot_rename_or_delete_someone_elses_private_view(): void
    {
        $owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        $privateView = $this->createSavedView($owner, 'Owner private view', SavedView::VISIBILITY_PRIVATE);

        $this->actingAs($agent)->patchJson("/marlin/saved-views/{$privateView->getKey()}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($agent)->deleteJson("/marlin/saved-views/{$privateView->getKey()}")
            ->assertForbidden();
    }

    public function test_an_agent_does_not_see_another_users_private_views_in_the_index(): void
    {
        $owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        $this->createSavedView($owner, 'Owner private view', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($agent)->getJson('/marlin/saved-views/accounts')->assertOk()->json();

        $this->assertSame([], $response['mine']);
        $this->assertSame([], $response['team']);
    }

    public function test_the_index_separates_my_views_from_team_views_and_reports_create_team_ability(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        $this->createSavedView($manager, 'Manager team view', SavedView::VISIBILITY_TEAM);
        $this->createSavedView($agent, 'Agent private view', SavedView::VISIBILITY_PRIVATE);

        $response = $this->actingAs($agent)->getJson('/marlin/saved-views/accounts')->assertOk()->json();

        $this->assertSame(['Agent private view'], array_column($response['mine'], 'name'));
        $this->assertSame(['Manager team view'], array_column($response['team'], 'name'));
        $this->assertFalse($response['can']['createTeam'], 'Un Agent nu poate transforma o vedere în echipă.');

        $managerResponse = $this->actingAs($manager)->getJson('/marlin/saved-views/accounts')->assertOk()->json();
        $this->assertTrue($managerResponse['can']['createTeam']);
    }

    private function createSavedView(User $user, string $name, string $visibility): SavedView
    {
        return TenantContext::run($this->tenant, function () use ($user, $name, $visibility): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
                'name' => $name,
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
