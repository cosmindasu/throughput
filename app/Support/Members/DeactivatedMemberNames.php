<?php

namespace App\Support\Members;

/**
 * FR-TEN-04 — placeholder „(deactivated)" pe orice referință de owner/creator/actor către
 * un membru cu `status = deactivated`, calculat server-side, într-un singur loc (task
 * US-TEN-03: „un singur helper — nu în paginile TSX").
 *
 * Fără N+1: un rând de listă (Accounts/Deals/Orders) sau feed-ul de activitate poate avea
 * zeci de referințe de owner pe aceeași pagină. Setul de user_id dezactivați ai tenantului
 * curent se încarcă O SINGURĂ DATĂ per tenant per proces, prin `DeactivatedMemberIds`
 * (instanță `scoped()`, cache ÎN proprietatea instanței — vezi docblock-ul acelei clase
 * pentru bug-ul găsit de coordonator în varianta anterioară, cu un binding de container
 * capturat într-o închidere).
 */
final class DeactivatedMemberNames
{
    public static function label(?string $name, ?string $userId): ?string
    {
        if ($name === null) {
            return null;
        }

        return self::isDeactivated($userId) ? trans('enums.membership.deactivated_name', ['name' => $name]) : $name;
    }

    public static function isDeactivated(?string $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return in_array($userId, app(DeactivatedMemberIds::class)->forCurrentTenant(), true);
    }
}
