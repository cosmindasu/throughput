<?php

namespace App\Policies;

use App\Models\User;

/**
 * §7.4, rândul „Setări curierat (furnizor activ + credențiale, ADR-010)": `CRUD` pentru
 * Owner, `—` pentru toate celelalte trei roluri — singurul rând din toată matricea unde
 * Managerul nu are măcar `R` (`App\Support\Permissions::forRoles()` exclude explicit
 * `carrier_settings.*` din setul lui). Nu există nicio îngustare ABAC aici (nu e o
 * resursă cu proprietate individuală, ca un cont sau un deal) — un singur gate per
 * permisiune e suficient, fără Policy de instanță.
 */
class TenantCarrierSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('carrier_settings.view');
    }

    public function manage(User $user): bool
    {
        return $user->can('carrier_settings.manage');
    }
}
