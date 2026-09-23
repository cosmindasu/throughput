<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * SEC-02 (audit de securitate 2026-09-23, `01-securitate.md`) — semnalul care decide dacă
 * `App\Http\Controllers\Webhooks\WebhookHealthController` are voie să rămână cross-tenant.
 *
 * `webhook_events` nu are `tenant_id`/RLS (§19.1): rândurile nu se pot filtra pe
 * workspace-ul curent, deci acel ecran arată TOATE evenimentele, ale TUTUROR tenanților.
 * Docblock-ul controllerului spune de ce e acceptabil AZI: „cei trei tenanți sunt
 * demo-urile aceluiași proprietar". Decizia proprietarului (2026-09-23): garda trebuie
 * pusă ACUM, verificabilă în cod — nu doar un comentariu, și nu un flag manual pe care
 * cineva trebuie să-și amintească să-l comute la primul tenant plătitor real.
 *
 * SEMNALUL ALES: există un singur utilizator care e Owner (rol Spatie, per tenant, §7.2)
 * ÎN FIECARE tenant existent? FR-TEN-01 descrie exact asta ca proprietatea demo-ului
 * actual („Owner e membru în toate cele 3 organizații" — Manager/Agent/Viewer nu sunt).
 * E DERIVAT din date reale (`model_has_roles`), nu declarat printr-un flag: se strică
 * singur, fără nicio intervenție, în clipa în care un tenant nou nu împarte niciun Owner
 * cu restul — exact situația „tenantul devine o organizație independentă" pe care
 * docblock-ul controllerului o numește drept condiția de mutare a ecranului la
 * super-admin.
 *
 * De ce NU un flag de config (`SINGLE_OWNER_DEPLOYMENT=true`): ar fi exact genul de gardă
 * „cineva trebuie să-și amintească să-l comute" pe care proprietarul a cerut explicit s-o
 * evităm — fail-open prin uitare, nu fail-closed. Un singur tenant (sau zero) e trivial
 * sigur — nu există „alt tenant" spre care să scurgă ceva — deci `active()` întoarce
 * `true` fără să mai interogheze rolurile.
 *
 * `roles`/`model_has_roles` sunt DELIBERAT fără RLS (vezi migrația
 * `2026_09_12_100011_create_permission_tables.php`), la fel ca `tenants` (vezi migrația
 * `create_tenants_table`, care nu are `tenant_id`/RLS din același motiv — e chiar unitatea
 * de scopare). Interogarea de mai jos NU ocolește nimic și nu slăbește RLS: vede tot ce ar
 * vedea oricum orice cod de sistem care iterează tenanții (`RoleAndPermissionSeeder`,
 * joburile din `App\Jobs\System`, `Tenant::query()->eachById(...)`), indiferent de
 * `app.tenant_id`-ul cererii curente.
 */
final class SingleOwnerDeployment
{
    /**
     * `true` cât timp toți tenanții existenți au un Owner comun (sau există cel mult un
     * tenant), `false` din clipa în care apare un tenant fără niciun Owner comun cu restul.
     */
    public static function active(): bool
    {
        $tenantCount = Tenant::query()->count();

        if ($tenantCount <= 1) {
            return true;
        }

        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');

        $rolesTable = $tableNames['roles'];
        $modelHasRolesTable = $tableNames['model_has_roles'];
        // Aceleași fallback-uri ca migrația pachetului (`role_pivot_key` e `null` în
        // `config/permission.php`, deci implicitul pachetului e `role_id`) — vezi
        // `2026_09_12_100011_create_permission_tables.php`.
        $rolePivotKey = $columnNames['role_pivot_key'] ?? 'role_id';
        $modelKey = $columnNames['model_morph_key'] ?? 'model_id';
        $teamKey = $columnNames['team_foreign_key'];

        // Există un `model_id` (user) cu rol Owner în EXACT atâția tenanți câți există în
        // total? Dacă da, acel utilizator e Owner peste tot — proprietatea FR-TEN-01.
        //
        // `select()` explicit pe coloana de grupare, NU implicitul `*`: `exists()` compilează
        // interogarea originală ca subquery sub `SELECT EXISTS(...)`, iar `SELECT *` peste
        // un `JOIN` cu `GROUP BY` doar pe `model_id` ar cere fiecare coloană neagregată
        // (`role_id`, `team_id`, ...) fie în `GROUP BY`, fie într-o funcție de agregare —
        // Postgres refuză cu „Grouping error" (reprodus, nu presupus).
        return DB::table($modelHasRolesTable)
            ->select("{$modelHasRolesTable}.{$modelKey}")
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.{$rolePivotKey}")
            ->where("{$rolesTable}.name", Permissions::OWNER)
            ->where("{$rolesTable}.guard_name", 'web')
            ->groupBy("{$modelHasRolesTable}.{$modelKey}")
            ->havingRaw("count(distinct {$modelHasRolesTable}.{$teamKey}) = ?", [$tenantCount])
            ->exists();
    }
}
