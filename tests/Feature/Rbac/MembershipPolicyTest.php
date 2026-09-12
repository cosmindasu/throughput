<?php

namespace Tests\Feature\Rbac;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BR-TEN-01/02/03 și [[ADR-011]]. Perechea de teste care contează e ultima: dezactivarea
 * unui membru cu înregistrări în lucru TREBUIE să treacă, iar dezactivarea ultimului Owner
 * TREBUIE să pice. Prima jumătate singură ar fi trecut și cu vechea regulă (blocantă), a
 * doua singură ar fi trecut și cu una care blochează totul.
 */
class MembershipPolicyTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
    }

    public function test_an_owner_can_deactivate_a_member_who_owns_open_records(): void
    {
        $owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->tenant, 'jane.doe@throughput.dev', Permissions::AGENT);

        $this->inTenant(function () use ($owner, $agent): void {
            $membership = $this->membershipOf($agent);

            // Jane deține conturi, oportunități deschise și comenzi active. Irelevant:
            // revocarea accesului are prioritate absolută (BR-TEN-03).
            $this->assertTrue(Gate::forUser($owner)->allows('deactivate', $membership));
        });
    }

    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->inTenant(function () use ($owner): void {
            $response = Gate::forUser($owner)->inspect('deactivate', $this->membershipOf($owner));

            $this->assertTrue($response->denied());
            $this->assertSame('Transfer ownership before deactivating the last Owner.', $response->message());
        });
    }

    public function test_an_owner_can_be_deactivated_once_a_second_owner_exists(): void
    {
        $first = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $second = $this->makeMember($this->tenant, 'second.owner@throughput.dev', Permissions::OWNER);

        $this->inTenant(function () use ($first, $second): void {
            $this->assertTrue(Gate::forUser($second)->allows('deactivate', $this->membershipOf($first)));
        });
    }

    public function test_a_manager_cannot_deactivate_an_owner(): void
    {
        $owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->makeMember($this->tenant, 'second.owner@throughput.dev', Permissions::OWNER);
        $manager = $this->makeMember($this->tenant, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $this->inTenant(function () use ($manager, $owner): void {
            $response = Gate::forUser($manager)->inspect('deactivate', $this->membershipOf($owner));

            $this->assertTrue($response->denied());
            $this->assertSame('Only an Owner can deactivate another Owner.', $response->message());
        });
    }

    public function test_a_manager_cannot_promote_someone_to_owner(): void
    {
        $manager = $this->makeMember($this->tenant, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->tenant, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->inTenant(function () use ($manager, $agent): void {
            $membership = $this->membershipOf($agent);

            $this->assertTrue(Gate::forUser($manager)->allows('updateRole', [$membership, Permissions::VIEWER]));
            $this->assertTrue(Gate::forUser($manager)->denies('updateRole', [$membership, Permissions::OWNER]));
        });
    }

    public function test_an_agent_cannot_manage_members_at_all(): void
    {
        $agent = $this->makeMember($this->tenant, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->tenant, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->inTenant(function () use ($agent, $viewer): void {
            $this->assertTrue(Gate::forUser($agent)->denies('deactivate', $this->membershipOf($viewer)));
            $this->assertTrue(Gate::forUser($agent)->denies('invite', Membership::class));
            $this->assertTrue(Gate::forUser($viewer)->denies('viewAny', Membership::class));
        });
    }

    private function membershipOf(User $user): Membership
    {
        return Membership::query()->where('user_id', $user->getKey())->firstOrFail();
    }

    private function inTenant(callable $fn): void
    {
        TenantContext::run($this->tenant, function () use ($fn): void {
            // Pasul pe care documentația pachetului îl are într-o notă și pe care e ușor
            // să-l omiți: fără el, verificările de rol interoghează tenantul greșit.
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getKey());

            $fn();
        });
    }
}
