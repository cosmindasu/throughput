<?php

namespace Tests\Feature\Members;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\DemoMode;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * US-TEN-02, §6.4 — „schimb rolul unui Manager în Viewer → modificarea e efectivă imediat
 * și înregistrată în activity_log (§17) cu valorile vechi și noi", plus cele două blocări:
 *
 *  - BR-TEN-01: „un workspace are întotdeauna minim un Owner activ; ultima
 *    eliminare/RETROGRADARE a unui Owner e blocată server-side (nu doar ascunsă în UI)";
 *  - BR-TEN-02: „doar Owner poate schimba rolul unui alt Owner".
 */
class MemberRoleChangeTest extends TestCase
{
    /** Cheia din `App\Support\DemoMode::GUARDED_ACTIONS`, înregistrată de lotul I. */
    private const DEMO_GUARD_KEY = 'members.change-role';

    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    public function test_an_owner_changes_a_managers_role_and_it_takes_effect_on_the_next_request(): void
    {
        // Premisa a ceea ce urmează: ca Manager, are acces la ecranul de membri (§7.4).
        $this->actingAs($this->manager)->get('/marlin/settings/members')->assertOk();

        $this->actingAs($this->owner)
            ->patch("/marlin/settings/members/{$this->membershipOf($this->manager)->getKey()}/role", [
                'role' => Permissions::VIEWER,
            ])
            ->assertRedirect('/marlin/settings/members')
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Viewer'));

        $this->assertSame(Permissions::VIEWER, $this->roleOf($this->manager));

        // „Efectivă imediat", nu la următorul login: Viewer-ul nu mai are `members.view`.
        //
        // `fresh()`, nu `$this->manager`: relația `roles` a fost deja încărcată pe ACEA
        // instanță la prima cerere de mai sus, iar instanța supraviețuiește între cereri
        // DOAR în teste (un singur proces PHPUnit). În producție fiecare cerere își încarcă
        // utilizatorul din bază, deci n-are ce să rămână în urmă — artefact de suită, nu un
        // gol de invalidare.
        $this->actingAs($this->manager->fresh())->get('/marlin/settings/members')->assertForbidden();
    }

    public function test_the_activity_log_records_the_old_and_new_role(): void
    {
        $this->actingAs($this->owner)
            ->patch("/marlin/settings/members/{$this->membershipOf($this->agent)->getKey()}/role", [
                'role' => Permissions::MANAGER,
            ]);

        $log = TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->sole());

        $this->assertSame('role_changed', $log->action);
        $this->assertSame(Permissions::AGENT, $log->old_values['role']);
        $this->assertSame(Permissions::MANAGER, $log->new_values['role']);
        $this->assertSame($this->owner->getKey(), $log->user_id);
    }

    /** BR-TEN-01 — blocare SERVER-SIDE, cu mesajul exact din Gherkin-ul US-TEN-02. */
    public function test_demoting_the_last_active_owner_is_blocked_server_side(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/role", [
                'role' => Permissions::MANAGER,
            ])
            ->assertSessionHasErrors(['role' => 'A workspace needs at least one Owner.']);

        $this->assertSame(Permissions::OWNER, $this->roleOf($this->owner));
    }

    public function test_an_owner_can_be_demoted_once_a_second_owner_exists(): void
    {
        $second = $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($second)
            ->patch("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/role", [
                'role' => Permissions::MANAGER,
            ])
            ->assertRedirect('/marlin/settings/members');

        $this->assertSame(Permissions::MANAGER, $this->roleOf($this->owner));
    }

    /** BR-TEN-02 — „doar Owner poate schimba rolul unui alt Owner". */
    public function test_a_manager_cannot_touch_an_owners_role(): void
    {
        $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->manager)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/role", [
                'role' => Permissions::VIEWER,
            ])
            ->assertSessionHasErrors(['role' => 'Only an Owner can promote or demote another Owner.']);

        $this->assertSame(Permissions::OWNER, $this->roleOf($this->owner));
    }

    /** BR-TEN-02, cealaltă direcție — „nu poate promova pe cineva la Owner". */
    public function test_a_manager_cannot_promote_anyone_to_owner(): void
    {
        $this->actingAs($this->manager)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->agent)->getKey()}/role", [
                'role' => Permissions::OWNER,
            ])
            ->assertSessionHasErrors(['role' => 'Only an Owner can promote or demote another Owner.']);

        $this->assertSame(Permissions::AGENT, $this->roleOf($this->agent));
    }

    public function test_a_manager_can_change_an_agent_to_a_viewer(): void
    {
        $this->actingAs($this->manager)
            ->patch("/marlin/settings/members/{$this->membershipOf($this->agent)->getKey()}/role", [
                'role' => Permissions::VIEWER,
            ])
            ->assertRedirect('/marlin/settings/members');

        $this->assertSame(Permissions::VIEWER, $this->roleOf($this->agent));
    }

    /** §7.4 — Agent și Viewer au „—" pe rândul „Membri și roluri". */
    public function test_an_agent_cannot_change_roles(): void
    {
        $this->actingAs($this->agent)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->viewer)->getKey()}/role", [
                'role' => Permissions::MANAGER,
            ])
            ->assertSessionHasErrors(['role' => 'You cannot change roles in this workspace.']);

        $this->assertSame(Permissions::VIEWER, $this->roleOf($this->viewer));
    }

    public function test_an_unknown_role_is_rejected_by_validation(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->agent)->getKey()}/role", [
                'role' => 'Superuser',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(Permissions::AGENT, $this->roleOf($this->agent));
    }

    /**
     * FR-RBAC-01 — „absent, nu dezactivat cu tooltip". Pentru un Manager, butonul „Change
     * role" lipsește de pe rândul unui Owner, iar lista lui de roluri asignabile nu conține
     * „Owner" — refuzul de la submit e al DOILEA strat, nu singurul.
     */
    public function test_the_props_hide_what_the_policy_would_refuse(): void
    {
        $this->actingAs($this->manager)
            ->get('/marlin/settings/members')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Settings/Members/Index')->where('can.updateRole', true);

                $members = collect($page->toArray()['props']['members']);

                $ownerRow = $members->firstWhere('user.email', 'owner@throughput.dev');
                $this->assertFalse($ownerRow['canUpdateRole'], 'Un Manager nu poate schimba rolul unui Owner.');

                $selfRow = $members->firstWhere('user.email', 'manager@throughput.dev');
                $this->assertFalse($selfRow['canUpdateRole'], 'Rândul propriu nu are buton de schimbare a rolului.');

                $agentRow = $members->firstWhere('user.email', 'agent@throughput.dev');
                $this->assertTrue($agentRow['canUpdateRole']);
                $this->assertSame(
                    [Permissions::MANAGER, Permissions::AGENT, Permissions::VIEWER],
                    $agentRow['assignableRoles'],
                );
            });
    }

    /**
     * Un rând care nu se schimbă nu scrie nimic: fără garda asta, un dublu-click pe
     * „Change role" cu aceeași valoare ar fi produs două rânduri „Agent → Agent" în
     * jurnalul de activitate (§17), adică zgomot într-un jurnal de audit.
     */
    public function test_setting_the_same_role_again_writes_nothing(): void
    {
        $this->actingAs($this->owner)
            ->patch("/marlin/settings/members/{$this->membershipOf($this->agent)->getKey()}/role", [
                'role' => Permissions::AGENT,
            ])
            ->assertRedirect('/marlin/settings/members');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->count()));
    }

    /**
     * §22.2, al doilea strat — lotul I a guardat `settings.members.role.update` în
     * DEMO_MODE (al doilea drum către „workspace fără Owner" e RETROGRADAREA, iar conturile
     * demo sunt login-uri partajate). Butonul trebuie să LIPSEASCĂ din props, nu să ducă la
     * un refuz (FR-RBAC-01).
     *
     * Se auto-activează la integrare: cât timp cheia nu e în `DemoMode::GUARDED_ACTIONS`
     * (registrul lotului I, `app/Support/DemoMode.php`, nu e un fișier al acestui lot),
     * testul se sare explicit în loc să treacă „verde" fără să verifice nimic. Aceeași
     * strategie ca `HelpTopicCoverageTest` pentru rutele construite în paralel.
     */
    public function test_the_change_role_affordance_is_hidden_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true]);

        if (DemoMode::allows(self::DEMO_GUARD_KEY)) {
            $this->markTestSkipped(
                'DemoMode::GUARDED_ACTIONS nu conține încă "'.self::DEMO_GUARD_KEY.'" (lotul I) — '
                .'`MembersController::index()` îl consultă deja, deci testul începe să verifice singur după merge.'
            );
        }

        $this->actingAs($this->owner)
            ->get('/marlin/settings/members')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Settings/Members/Index')->where('can.updateRole', false);

                foreach ($page->toArray()['props']['members'] as $row) {
                    $this->assertFalse($row['canUpdateRole'], 'Niciun rând nu are voie să ofere „Change role" în DEMO_MODE.');
                }
            });
    }

    /**
     * Regula de BUSINESS trebuie să existe independent de garda de mediu: cu DEMO_MODE
     * stins, BR-TEN-01 blochează tot retrogradarea ultimului Owner. Garda lui I e
     * deliberat mai grosieră (oprește ORICE schimbare de rol în demo) și nu ține loc de
     * regulă — dovada e chiar testul `test_demoting_the_last_active_owner_is_blocked_server_side`
     * de mai sus, care rulează cu demo stins.
     */
    public function test_the_business_rule_does_not_depend_on_the_demo_guardrail(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->patch("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/role", [
                'role' => Permissions::VIEWER,
            ])
            ->assertSessionHasErrors(['role' => 'A workspace needs at least one Owner.']);

        $this->assertSame(Permissions::OWNER, $this->roleOf($this->owner));
    }

    /** §18.5 — un membership din alt tenant nu se confirmă nici măcar că există. */
    public function test_changing_the_role_of_a_membership_from_another_tenant_is_a_404(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $foreigner = $this->makeMember($cascade, 'foreigner@throughput.dev', Permissions::AGENT);
        $foreignId = TenantContext::run($cascade, fn () => Membership::query()
            ->where('user_id', $foreigner->getKey())->sole()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->patch("/marlin/settings/members/{$foreignId}/role", ['role' => Permissions::VIEWER])
            ->assertNotFound();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($cascade->getKey());
        $this->assertSame(Permissions::AGENT, $foreigner->fresh()->getRoleNames()->first());
        $registrar->setPermissionsTeamId(null);
    }

    private function membershipOf(User $user): Membership
    {
        $membership = TenantContext::run(
            $this->marlin,
            fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail(),
        );

        $this->clearDatabaseTenantContext();

        return $membership;
    }

    private function roleOf(User $user): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->marlin->getKey());

        $role = $user->fresh()->getRoleNames()->first();

        $registrar->setPermissionsTeamId(null);

        return $role;
    }
}
