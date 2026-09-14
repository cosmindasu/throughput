<?php

namespace Tests\Feature\Bulk;

use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Permissions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FR-BULK-01 — `min(25% × plafonul rolului, 1.000)`, o singură expresie citită atât de
 * validarea serverului cât și de props-ul dialogului. 125 pentru Agent (plafon 500,
 * BR-BULK-02), 1.000 pentru Owner/Manager (fără plafon de rol).
 */
class BulkConfirmationThresholdTest extends TestCase
{
    public function test_the_threshold_is_125_for_agent_and_1000_for_owner_and_manager(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);
        $manager = $this->makeMember($tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($tenant, 'agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($tenant, 'viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        // `Permissions::restrictedToOwnRecords()` verifică rolul prin Spatie
        // (`$user->hasRole()`), scopat pe team (`tenant_id`) — fără un context HTTP care să-l
        // seteze (middleware-ul de workspace), `clearDatabaseTenantContext()` l-a resetat la
        // `null`. Setat aici explicit, ca `makeMember()` intern.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        $this->assertSame(1000, BulkConfirmationThreshold::for($owner));
        $this->assertNull(BulkConfirmationThreshold::rowCapForRole($owner));

        $this->assertSame(1000, BulkConfirmationThreshold::for($manager));
        $this->assertNull(BulkConfirmationThreshold::rowCapForRole($manager));

        $this->assertSame(125, BulkConfirmationThreshold::for($agent));
        $this->assertSame(500, BulkConfirmationThreshold::rowCapForRole($agent));

        // Viewer nu declanșează nicio operație de scriere (BR-BULK-03), dar formula rămâne
        // definită pentru orice rol — nu e o sursă de 500 pe un ecran pe care Viewer nu-l atinge.
        $this->assertSame(1000, BulkConfirmationThreshold::for($viewer));
    }

    public function test_the_agent_row_cap_is_configurable(): void
    {
        config(['throughput.limits.bulk_agent_row_cap' => 200]);

        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $agent = $this->makeMember($tenant, 'agent@throughput.dev', Permissions::AGENT);
        $this->clearDatabaseTenantContext();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        $this->assertSame(200, BulkConfirmationThreshold::rowCapForRole($agent));
        $this->assertSame(50, BulkConfirmationThreshold::for($agent));
    }
}
