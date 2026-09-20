<?php

namespace Tests\Feature\Activity;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-AUD-03, §7.4 (rândul „Jurnal de activitate") — Owner/Manager văd tot tenantul, Agentul
 * doar acțiunile proprii, Viewer-ul deloc. Distinct de tab-ul „History" al unei entități
 * (`ActivityLogEntityHistoryTest`), fără restricția de rol de mai jos.
 */
class ActivityLogIndexTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function (): void {
            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'updated',
                'auditable_type' => 'App\\Models\\Account',
                'auditable_id' => (string) Str::ulid(),
                'old_values' => ['name' => 'Old'],
                'new_values' => ['name' => 'New'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
            ActivityLog::query()->create([
                'user_id' => $this->agent->getKey(),
                'action' => 'created',
                'auditable_type' => 'App\\Models\\Account',
                'auditable_id' => (string) Str::ulid(),
                'new_values' => ['name' => 'Agent made this'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();
    }

    public function test_owner_sees_every_row_in_the_tenant(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 2)
            ->where('canFilterByUser', true));
    }

    public function test_agent_sees_only_their_own_actions(): void
    {
        $response = $this->actingAs($this->agent)->get('/marlin/activity');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.actor.id', $this->agent->getKey())
            ->where('canFilterByUser', false)
            ->where('members', []));
    }

    public function test_viewer_cannot_access_the_tenant_wide_journal(): void
    {
        $this->actingAs($this->viewer)->get('/marlin/activity')->assertForbidden();
    }

    /**
     * `App\Http\Middleware\HandleInertiaRequests::navigationPermissions()` — clé combinée
     * pentru `NavItem` (un singur `permission` per intrare, `AppLayout.tsx`): adevărat
     * pentru Owner/Agent (fiecare are UNA din cele două permisiuni), fals pentru Viewer.
     */
    public function test_the_combined_nav_permission_reflects_either_underlying_permission(): void
    {
        // `Record<string, boolean>` e un obiect PLAT (chei „accounts.view" literale, nu
        // imbricate): `AssertableJson::where()` cu un path pe puncte ar naviga „navigation
        // → activity_log → any_view" ca TREI niveluri (`Arr::get()`, fără suport de escape
        // în această versiune) — se citește cheia PLATĂ direct, printr-un closure pe
        // `navigation` ca întreg.
        $this->actingAs($this->owner)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === true));

        $this->actingAs($this->agent)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === true));

        $this->actingAs($this->viewer)->get('/marlin/dashboard')
            ->assertInertia(fn ($page) => $page->where('navigation', fn ($navigation) => $navigation['activity_log.any_view'] === false));
    }

    public function test_filtering_by_action_narrows_the_list(): void
    {
        $response = $this->actingAs($this->owner)->get('/marlin/activity?action=created');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'created'));
    }
}
