<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings shell (plan §7.4) — secțiunile vizibile diferă pe rol, calculate
 * server-side (§7.3, FR-RBAC-01). O secțiune fără drept LIPSEȘTE din `can`, nu e doar
 * dezactivată: `Settings/Index.tsx` filtrează exact pe aceste chei (badge „Coming in a
 * later phase" pentru Members/Billing/API Tokens — Faza 5 — niciodată un link), deci
 * un test de props e suficient aici; randarea JSX propriu-zisă nu intră în acest pachet
 * (axe-core/E2E sunt ale altui pachet).
 */
class SettingsShellTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_the_owner_sees_every_section(): void
    {
        $this->assertSections($this->owner, [
            'members' => true,
            'billing' => true,
            'apiTokens' => true,
            'carrierSettings' => true,
            'pipeline' => true,
            'preferences' => true,
        ]);
    }

    public function test_the_manager_sees_everything_except_billing_and_carrier_settings(): void
    {
        // Criteriul de acceptanță §7.3: Managerul NU vede „Billing & Subscription",
        // deși are acces operațional complet pe rest. Din Faza 5, nici „Carrier
        // settings" (FR-ORD-06): `carrier_settings.*` lipsește deliberat din rolul lui
        // în Permissions::forRoles() — credențialele de curierat sunt secrete ale
        // organizației, nu configurare operațională.
        $manager = $this->makeMember($this->tenant, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->assertSections($manager, [
            'members' => true,
            'billing' => false,
            'apiTokens' => true,
            'carrierSettings' => false,
            'pipeline' => true,
            'preferences' => true,
        ]);
    }

    public function test_the_agent_sees_only_preferences(): void
    {
        // Matricea §7.4: Agent — „—" pe membri, billing, jetoane API ȘI pe
        // configurarea de pipeline/etape (spre deosebire de Viewer, care are „R").
        $agent = $this->makeMember($this->tenant, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->assertSections($agent, [
            'members' => false,
            'billing' => false,
            'apiTokens' => false,
            'carrierSettings' => false,
            'pipeline' => false,
            'preferences' => true,
        ]);
    }

    public function test_the_viewer_sees_only_pipeline_and_preferences(): void
    {
        $viewer = $this->makeMember($this->tenant, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->assertSections($viewer, [
            'members' => false,
            'billing' => false,
            'apiTokens' => false,
            'carrierSettings' => false,
            'pipeline' => true,
            'preferences' => true,
        ]);
    }

    public function test_the_preferences_page_is_reachable_by_every_role(): void
    {
        $viewer = $this->makeMember($this->tenant, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)
            ->get('/marlin/settings/preferences')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Preferences'));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/marlin/settings')->assertRedirect('/login');
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertSections(User $user, array $expected): void
    {
        $this->actingAs($user)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->where('can', function ($can) use ($expected): bool {
                    foreach ($expected as $key => $value) {
                        if (($can[$key] ?? null) !== $value) {
                            return false;
                        }
                    }

                    return true;
                })
            );
    }
}
