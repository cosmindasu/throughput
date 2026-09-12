<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cele 4 roluri (per tenant) și întregul catalog de permisiuni (global) — plan §7.3.
 *
 * Permisiunile sunt globale, rolurile sunt per tenant: `permissions` nu are
 * `team_foreign_key`, `roles` are. Consecință practică — același om poate fi Owner
 * într-o organizație și Viewer în alta, fără ca lista de permisiuni să se dubleze.
 *
 * Rulează și pentru tenanții creați ulterior: e idempotent (`findOrCreate` + `sync`),
 * deci `demo:reset` îl poate reapela fără să acumuleze duplicate.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Cache-ul pachetului se golește ÎNAINTE și DUPĂ: altfel un rol creat acum e
        // invizibil pentru verificările din același proces (§7.2 — pasul omis frecvent,
        // reluat și în checklistul de deployment §25.3).
        $registrar->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Tenant::query()->eachById(fn (Tenant $tenant) => $this->seedTenant($tenant));

        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }

    /**
     * Rolurile unui singur tenant. Public pentru că îl cheamă și seeder-ul de volum (după
     * ce creează un tenant nou) și harnessul de testare — ca regulile de rol să existe
     * într-un singur loc, nu într-o copie „doar pentru teste" care se poate desincroniza.
     */
    public function seedTenant(Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        foreach (Permissions::forRoles() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }
    }
}
