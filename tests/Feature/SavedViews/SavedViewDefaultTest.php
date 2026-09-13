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
 * FR-VIEW-02, cap-coadă: implicitul personal, aplicarea lui la deschiderea listei, și
 * notificarea o singură dată când vederea „Team" folosită ca implicit dispare.
 */
class SavedViewDefaultTest extends TestCase
{
    private Tenant $tenant;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);
    }

    public function test_opening_the_list_with_no_filters_applies_the_saved_default(): void
    {
        // `owner=all` EXPLICIT în vedere — altfel cheia lipsă ar fi, corect, reumplută cu
        // implicitul de rol la reîncărcare (`AccountList::defaultFilters()`), fiindcă URL-ul
        // rezultat n-ar mai conține-o deloc (§15.2, „URL-ul e sursa de adevăr"): un implicit
        // salvat înlocuiește doar CE a capturat vederea, nu inventează chei noi.
        $teamView = $this->createSavedView($this->manager, ['status' => 'active', 'owner' => 'all'], 'name', SavedView::VISIBILITY_TEAM);

        $this->actingAs($this->agent)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $teamView->getKey()])
            ->assertOk()
            ->assertJsonPath('defaultId', $teamView->getKey());

        $response = $this->actingAs($this->agent)->get('/marlin/accounts');
        $response->assertRedirect();
        $this->assertStringContainsString('sort=name', (string) $response->headers->get('Location'));

        // Câștigă în fața implicitului de rol al Agentului („My accounts"): vederea salvată
        // cere explicit `status=active&owner=all`.
        $this->actingAs($this->agent)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('list.filter.status', 'active')
                ->where('list.filter.owner', 'all')
            );
    }

    public function test_a_url_with_explicit_filters_ignores_the_saved_default(): void
    {
        $teamView = $this->createSavedView($this->manager, ['status' => 'active'], 'name', SavedView::VISIBILITY_TEAM);

        $this->actingAs($this->agent)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $teamView->getKey()])
            ->assertOk();

        // Link partajat, cu filtru explicit propriu — §15.2, „vederea nu e o stare ascunsă".
        $this->actingAs($this->agent)->get('/marlin/accounts?filter[status]=inactive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('list.filter.status', 'inactive'));
    }

    public function test_removing_the_default_stops_it_from_being_applied(): void
    {
        $teamView = $this->createSavedView($this->manager, ['status' => 'active'], 'name', SavedView::VISIBILITY_TEAM);

        $this->actingAs($this->agent)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $teamView->getKey()])
            ->assertOk();

        $this->actingAs($this->agent)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => null])
            ->assertOk()
            ->assertJsonPath('defaultId', null);

        TenantContext::run($this->tenant, function (): void {
            $this->assertDatabaseMissing('saved_view_defaults', [
                'user_id' => $this->agent->getKey(),
                'resource_type' => 'accounts',
            ]);
        });

        // Fără implicit: revine implicitul de ROL obișnuit al Agentului.
        $this->actingAs($this->agent)->get('/marlin/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('list.filter.owner', 'me'));
    }

    public function test_deleting_the_default_team_view_shows_a_one_time_notice_and_clears_the_default(): void
    {
        $teamView = $this->createSavedView($this->manager, [], 'name', SavedView::VISIBILITY_TEAM);

        $this->actingAs($this->agent)
            ->putJson('/marlin/saved-views/accounts/default', ['saved_view_id' => $teamView->getKey()])
            ->assertOk();

        $this->actingAs($this->manager)->deleteJson("/marlin/saved-views/{$teamView->getKey()}")->assertNoContent();

        // Rândul supraviețuiește ștergerii vederii — FK `nullOnDelete()`, nu o curățare
        // manuală în `destroy()`.
        TenantContext::run($this->tenant, function (): void {
            $this->assertDatabaseHas('saved_view_defaults', [
                'user_id' => $this->agent->getKey(),
                'resource_type' => 'accounts',
                'saved_view_id' => null,
            ]);
        });

        $this->actingAs($this->agent)->get('/marlin/accounts')
            ->assertOk()
            ->assertSessionHas('notice', 'The team view you used as default was deleted.');

        // O singură dată: rândul orfan a fost șters la prima vizită de mai sus.
        TenantContext::run($this->tenant, function (): void {
            $this->assertDatabaseMissing('saved_view_defaults', [
                'user_id' => $this->agent->getKey(),
                'resource_type' => 'accounts',
            ]);
        });

        $this->actingAs($this->agent)->get('/marlin/accounts')
            ->assertOk()
            ->assertSessionMissing('notice');
    }

    private function createSavedView(User $user, array $filters, string $sort, string $visibility): SavedView
    {
        return TenantContext::run($this->tenant, function () use ($user, $filters, $sort, $visibility): SavedView {
            $view = new SavedView([
                'resource_type' => 'accounts',
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
