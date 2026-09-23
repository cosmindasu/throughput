<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

/**
 * FR-API-02 — „Doar Owner și Manager pot crea/revoca jetoane API" (§7.4).
 *
 * Regula nu e scrisă aici ca verificare de ROL, ci ca verificare de PERMISIUNE:
 * `api_tokens.view` / `.create` / `.revoke` există deja în `App\Support\Permissions`
 * din Faza 1, mapate pe Owner și Manager. Un Policy care ar întreba `hasRole('Owner')`
 * ar fi a doua sursă de adevăr peste matricea §7.4 — exact ce docblock-ul catalogului
 * interzice.
 *
 * Nu există `update`: un jeton emis nu se editează (scopurile lui sunt scrise în rândul
 * Sanctum la emitere și în jetonul deja livrat integratorului). Se revocă și se emite
 * altul — la fel ca la orice cheie de API.
 *
 * Înregistrarea e prin descoperire automată (`App\Models\ApiToken` → `App\Policies\
 * ApiTokenPolicy`), ca toate celelalte Policies din proiect: nu există `AuthServiceProvider`
 * și niciun `Gate::policy()` nicăieri.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('api_tokens.view');
    }

    public function view(User $user, ApiToken $token): bool
    {
        return $user->can('api_tokens.view');
    }

    public function create(User $user): bool
    {
        return $user->can('api_tokens.create');
    }

    /**
     * Revocarea e o acțiune proprie, nu `delete`: rândul rămâne (istoricul „cine a emis,
     * când a fost revocat"), doar jetonul încetează să funcționeze.
     */
    public function revoke(User $user, ApiToken $token): bool
    {
        return $user->can('api_tokens.revoke');
    }

    /**
     * Revocarea ÎN MASĂ (§22.2, „Revocarea în masă a tuturor jetoanelor API", decizia
     * proprietarului din 2026-09-22: butonul se construiește). Aceeași permisiune ca
     * `revoke()` de mai sus — FR-API-02 nu distinge „un jeton" de „toate jetoanele", iar
     * matricea §7.4 nu are un rând separat pentru varianta în masă. Fără instanță de
     * model: acțiunea nu privește UN jeton anume, ci toate ale workspace-ului curent.
     */
    public function revokeAll(User $user): bool
    {
        return $user->can('api_tokens.revoke');
    }
}
