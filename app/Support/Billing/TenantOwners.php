<?php

namespace App\Support\Billing;

use App\Models\Membership;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Destinatarii dunning-ului (FR-BILL-04): toți Owner-ii ACTIVI ai unui tenant DAT EXPLICIT
 * prin id — NU `App\Support\Members\ActiveOwners::forCurrentTenant()`, care citește
 * `app('tenant')->getKey()`.
 *
 * De ce o versiune separată și nu reutilizarea directă a lui `ActiveOwners`: acel helper
 * presupune legătura `'tenant'` din container, pusă de `ResolveWorkspace` — validă în
 * timpul unei cereri HTTP, dar INEXISTENTĂ într-un job de coadă (proces de worker separat,
 * care n-a rulat niciodată acel middleware). `App\Listeners\Billing\SendPaymentFailedDunningEmail`
 * rulează în `App\Services\Tenancy\TenantContext::run($tenantId, ...)`, care setează DOAR
 * `TenantScope::CONTAINER_KEY` (id-ul, pentru RLS/global scope) — niciodată modelul
 * `'tenant'` din container. A lega manual `'tenant'` doar pentru acest apel ar fi mai
 * riscant decât o interogare separată: `app()->instance('tenant', ...)` NU e urmărit de
 * `scoped()`, deci NU se golește de `forgetScopedInstances()` între joburi — exact bug-ul
 * de memoizare cross-tenant pe care `.ai/rules/tenancy.md` îl documentează explicit pentru
 * alte cazuri.
 *
 * Interogarea în sine e identică cu `ActiveOwners::forCurrentTenant()` (rol Owner ∩
 * membership activ), doar parametrizată explicit.
 */
final class TenantOwners
{
    /** @return Collection<int, User> */
    public static function forTenant(string $tenantId): Collection
    {
        $ownerIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Permissions::OWNER)
            ->where('roles.tenant_id', $tenantId)
            ->where('model_has_roles.tenant_id', $tenantId)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('model_has_roles.model_id');

        $activeOwnerUserIds = Membership::query()
            ->whereIn('user_id', $ownerIds)
            ->where('status', Membership::STATUS_ACTIVE)
            ->pluck('user_id');

        return User::query()->whereIn('id', $activeOwnerUserIds)->get();
    }
}
