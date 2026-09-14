<?php

namespace App\Support\Bulk;

use App\Actions\Bulk\ReassignOwnerAction;
use InvalidArgumentException;

/**
 * Harta `action` (coloana `bulk_operations.action`) → executorul de chunk care o știe face.
 * Un singur executor poate servi mai multe `resource_type` (ex: `ReassignOwnerAction`
 * lucrează la fel pe Accounts și Deals) — genericitatea e pe `BulkWritableResource`, nu aici.
 */
final class BulkChunkActions
{
    public const REASSIGN_OWNER = 'reassign_owner';

    /** @return array<string, class-string<BulkChunkAction>> */
    public static function map(): array
    {
        return [
            self::REASSIGN_OWNER => ReassignOwnerAction::class,
        ];
    }

    public static function resolve(string $action): BulkChunkAction
    {
        $class = self::map()[$action] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Acțiunea în masă \"{$action}\" nu are un executor de chunk înregistrat.");
        }

        return app($class);
    }

    /**
     * Code review P3 — distinge o operație bazată pe `Bus::batch()` (`reassign_owner` și
     * orice acțiune viitoare din `map()`) de un export (`action === 'export'`,
     * `App\Support\Exports\ListExport`): exporturile rulează ca UN job unic
     * (`ExportListJob`), fără batch și fără `batch_id`, deci n-au ce anula prin
     * `$batch->cancel()` și nu au o pagină de progres cu „Cancel" (`Bulk/Show`) —
     * rămân pe ruta lor (`exports.show`). Folosit de `BulkOperationPolicy::cancel()` și
     * de `BulkOperationController::show()`.
     */
    public static function isRegistered(string $action): bool
    {
        return array_key_exists($action, self::map());
    }
}
