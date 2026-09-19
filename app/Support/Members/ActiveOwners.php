<?php

namespace App\Support\Members;

use App\Models\Membership;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Destinatarii notificării „N records need a new owner" (BR-TEN-06): TOȚI Owner-ii ACTIVI
 * ai tenantului curent — la fel ca resetarea de parolă (§22.3), nu doar cel care a
 * declanșat dezactivarea.
 *
 * Aceeași interogare ca `MembershipPolicy::activeOwnerCount()` (rol Owner ∩ membership activ
 * în tenantul curent), dar întoarce UTILIZATORII, nu doar numărul lor — Policy-ul rămâne
 * privat clasei lui, acest helper e public, pentru cine are nevoie de destinatari reali.
 */
final class ActiveOwners
{
    /** @return Collection<int, User> */
    public static function forCurrentTenant(): Collection
    {
        $tenantId = app('tenant')->getKey();

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
