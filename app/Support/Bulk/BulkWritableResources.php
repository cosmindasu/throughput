<?php

namespace App\Support\Bulk;

use App\Support\Bulk\Resources\AccountBulkResource;
use App\Support\Bulk\Resources\DealBulkResource;
use App\Support\Bulk\Resources\OrderBulkResource;
use App\Support\Bulk\Resources\ProductBulkResource;
use InvalidArgumentException;

/**
 * Harta `resource_type` → descrierea resursei scriabile în masă — mirror-ul lui
 * `App\Support\Exports\ExportableResources`, pentru latura de SCRIERE (§13.5). Sursă unică
 * pentru `DispatchBulkOperationAction`, `PlanBulkOperationJob` și `ProcessBulkChunkJob`:
 * toți trei rezolvă resursa prin același nume, deci nu pot ajunge să interogheze lucruri
 * diferite pentru aceeași operație.
 */
final class BulkWritableResources
{
    /** @return array<string, class-string<BulkWritableResource>> */
    public static function map(): array
    {
        return [
            'accounts' => AccountBulkResource::class,
            'deals' => DealBulkResource::class,
            'orders' => OrderBulkResource::class,
            'products' => ProductBulkResource::class,
        ];
    }

    public static function resolve(string $resourceType): BulkWritableResource
    {
        $class = self::map()[$resourceType] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Resursa \"{$resourceType}\" nu are o operație în masă de scriere înregistrată.");
        }

        return app($class);
    }
}
