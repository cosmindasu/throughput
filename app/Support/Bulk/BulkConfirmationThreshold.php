<?php

namespace App\Support\Bulk;

use App\Models\User;
use App\Support\Permissions;

/**
 * FR-BULK-01 — O SINGURĂ expresie: `min(25% × plafonul rolului, 1.000)`, citită atât de
 * validarea serverului (`App\Actions\Bulk\DispatchBulkOperationAction`), cât și de props-ul
 * dialogului de confirmare din React (`can.bulkConfirmationThreshold` pe paginile Index).
 *
 * 125 pentru Agent (plafon 500 — BR-BULK-02, `config('throughput.limits.bulk_agent_row_cap')`),
 * 1.000 pentru Owner/Manager (fără plafon de rol dincolo de acest prag de confirmare).
 *
 * De ce prag RELATIV, nu fix (specs.md §13.1, v1.9): un prag fix de 1.000 făcea dialogul
 * invizibil pentru Agent — exact rolul cu cea mai mică marjă de eroare rămânea singurul
 * fără confirmare.
 */
final class BulkConfirmationThreshold
{
    public const ABSOLUTE_CAP = 1000;

    /**
     * Plafonul DUR per operație (BR-BULK-02) — nu doar pragul de confirmare. `null` =
     * fără plafon de rol (Owner/Manager).
     */
    public static function rowCapForRole(User $user): ?int
    {
        return Permissions::restrictedToOwnRecords($user)
            ? (int) config('throughput.limits.bulk_agent_row_cap')
            : null;
    }

    public static function for(User $user): int
    {
        $roleCap = self::rowCapForRole($user);

        return $roleCap === null
            ? self::ABSOLUTE_CAP
            : (int) min((int) round($roleCap * 0.25), self::ABSOLUTE_CAP);
    }

    public static function exceeds(User $user, int $rows): bool
    {
        return $rows > self::for($user);
    }
}
