<?php

namespace Tests\Feature\Members;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\DemoMode;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * §22.2 (extindere semnalată în raportul pachetului, nu un rând existent al tabelului) —
 * conturile demo (§4.2) sunt LOGIN-URI PARTAJATE. Dezactivarea unui membru demo ar rupe
 * autentificarea tuturor vizitatorilor următori până la reset-ul de 03:00 UTC, exact riscul
 * distructiv pe care §22.2 îl tratează pentru ștergerea unui workspace — deci aceeași
 * regulă: oprit, indiferent de rol, chiar și pentru Owner (BR-DEMO-01).
 */
class MembersDemoModeGuardrailTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
    }

    public function test_deactivating_a_member_is_refused_in_demo_mode_even_for_an_owner(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($this->jane)->getKey()}/deactivate", ['reassign' => false])
            ->assertForbidden()
            ->assertSee(DemoMode::refusal('members.deactivate'));

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($this->jane)->fresh());
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status);
    }

    public function test_the_deactivate_button_is_hidden_from_the_members_list_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->get('/marlin/settings/members')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Members/Index')
                ->where('members.1.canDeactivate', false)
            );
    }

    public function test_deactivation_works_normally_once_demo_mode_is_off(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($this->jane)->getKey()}/deactivate", ['reassign' => false])
            ->assertRedirect('/marlin/settings/members');

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($this->jane)->fresh());
        $this->assertSame(Membership::STATUS_DEACTIVATED, $membership->status);
    }

    private function membershipOf(User $user): Membership
    {
        return TenantContext::run($this->marlin, fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail());
    }
}
