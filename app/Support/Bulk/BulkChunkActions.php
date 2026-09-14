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
}
