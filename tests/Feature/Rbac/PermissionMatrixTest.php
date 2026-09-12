<?php

namespace Tests\Feature\Rbac;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Matricea §7.4, verificată celulă cu celulă pe rândurile unde o greșeală s-ar vedea
 * direct în demo. Codul verifică PERMISIUNI, nu roluri (`$user->can('deals.edit')`,
 * niciodată `$user->hasRole('Manager')` într-un controller) — testul face la fel.
 *
 * Celulele alese nu sunt aleatorii: fiecare e un loc unde specificația a fost corectată
 * explicit (nota ³ despre exportul Viewer-ului, ² despre dezactivarea membrilor) sau unde
 * un „încă o permisiune, ce strică" ar trece neobservat la review.
 */
class PermissionMatrixTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function matrix(): array
    {
        return [
            'Owner' => [
                Permissions::OWNER,
                ['billing.manage', 'members.deactivate', 'carrier_settings.manage', 'api_tokens.create', 'data_exports.create', 'deals.delete', 'bulk.write'],
                [],
            ],
            'Manager' => [
                Permissions::MANAGER,
                ['members.invite', 'orders.create', 'imports.create', 'reports.manage', 'activity_log.view', 'api_tokens.create'],
                // Fără billing (doar citire), fără setări de curierat, fără export GDPR nou.
                ['billing.manage', 'carrier_settings.manage', 'carrier_settings.view', 'data_exports.create'],
            ],
            'Agent' => [
                Permissions::AGENT,
                ['accounts.edit', 'deals.move_stage', 'orders.create', 'shipments.create', 'bulk.write', 'bulk.export', 'activity_log.view_own'],
                // „—" în matrice: stoc, plăți, import, membri, pipeline, jurnal complet,
                // schimbarea proprietarului unui deal.
                ['stock.adjust', 'payments.create', 'payments.view', 'imports.create', 'members.view', 'pipelines.view', 'activity_log.view', 'deals.change_owner'],
            ],
            'Viewer' => [
                Permissions::VIEWER,
                // Exportul E o citire a rândurilor deja vizibile pe ecran (nota ³ / BR-BULK-03,
                // persona „contabil extern" din §5). Un refuz aici n-ar proteja nimic.
                ['accounts.view', 'orders.view', 'invoices.view', 'payments.view', 'bulk.export', 'saved_views.manage_own'],
                ['accounts.create', 'accounts.edit', 'deals.move_stage', 'bulk.write', 'orders.create', 'billing.view', 'reports.view', 'activity_log.view'],
            ],
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $denied
     */
    #[DataProvider('matrix')]
    public function test_role_permissions_match_the_specification(string $role, array $allowed, array $denied): void
    {
        $user = $this->makeMember($this->tenant, strtolower($role).'@throughput.dev', $role);

        TenantContext::run($this->tenant, function () use ($user, $role, $allowed, $denied): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getKey());

            foreach ($allowed as $permission) {
                $this->assertTrue($user->can($permission), "{$role} ar trebui să poată `{$permission}`.");
            }

            foreach ($denied as $permission) {
                $this->assertFalse($user->can($permission), "{$role} NU ar trebui să poată `{$permission}`.");
            }
        });
    }

    public function test_a_role_granted_in_one_workspace_does_not_leak_into_another(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $user = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::VIEWER, user: $user);

        // Exact modelul din §7.2: teams activat, `team_foreign_key = tenant_id`. Fără el,
        // promovarea cuiva la Owner într-un workspace l-ar fi făcut Owner peste tot.
        TenantContext::run($this->tenant, function () use ($user): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getKey());
            $user->unsetRelation('roles')->forgetCachedPermissions();

            $this->assertTrue($user->can('billing.manage'));
        });

        TenantContext::run($cascade, function () use ($user, $cascade): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($cascade->getKey());
            $user->unsetRelation('roles')->forgetCachedPermissions();

            $this->assertFalse($user->can('billing.manage'), 'Rolul dintr-un workspace a scurs în celălalt.');
            $this->assertTrue($user->can('accounts.view'));
        });
    }
}
