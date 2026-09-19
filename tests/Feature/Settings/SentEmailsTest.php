<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings → Sent Emails (BR-DEMO-02, specs.md §22.3) — RBAC (`sent_emails.view`, NOT
 * `settings.view`, App\Policies\SentEmailPolicy).
 *
 * Izolarea de tenant e testată separat, în `SentEmailIsolationTest` (consecvent cu
 * `ContactIsolationTest` din proiect — o clasă dedicată per resursă, nu amestecată cu RBAC).
 */
class SentEmailsTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    public function test_the_settings_shell_advertises_the_section_only_to_owner_and_manager(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.sentEmails', true));

        $this->actingAs($this->manager)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.sentEmails', true));

        $this->actingAs($this->agent)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.sentEmails', false));

        $this->actingAs($this->viewer)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.sentEmails', false));
    }

    public function test_owner_and_manager_can_open_the_journal(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/sent-emails')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/SentEmails/Index'));

        $this->actingAs($this->manager)
            ->get('/marlin/settings/sent-emails')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/SentEmails/Index'));
    }

    /**
     * Un jurnal cu conținutul complet al fiecărui email nu e pentru oricine (mandatul
     * pachetului) — Agent și Viewer primesc 403 la accesul direct, nu doar un link ascuns.
     */
    public function test_agent_and_viewer_are_forbidden_even_with_a_direct_link(): void
    {
        $this->actingAs($this->agent)->get('/marlin/settings/sent-emails')->assertForbidden();
        $this->actingAs($this->viewer)->get('/marlin/settings/sent-emails')->assertForbidden();
    }

    /**
     * Cu `DEMO_MODE=false` transportul nu mai jurnalizează niciun rând, deci ecranul ar
     * rămâne permanent gol, fără explicație. Proiectul ascunde ce nu se poate folosi, nu îl
     * dezactivează (§7.3) — iar garda stă în `SentEmailPolicy`, nu în controller, ca un link
     * direct să n-o poată ocoli. Testul verifică AMBELE capete, exact fiindcă `.env.testing`
     * are `DEMO_MODE=true`: fără el, garda ar fi cod netestat.
     */
    public function test_the_journal_disappears_entirely_when_demo_mode_is_off(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)
            ->get('/marlin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.sentEmails', false));

        $this->actingAs($this->owner)->get('/marlin/settings/sent-emails')->assertForbidden();
    }
}
