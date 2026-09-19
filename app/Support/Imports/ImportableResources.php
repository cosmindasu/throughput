<?php

namespace App\Support\Imports;

use App\Support\Imports\Resources\AccountImportResource;
use App\Support\Imports\Resources\ContactImportResource;
use App\Support\Imports\Resources\ProductImportResource;
use App\Support\Imports\Resources\VariantImportResource;
use InvalidArgumentException;

/**
 * Harta `resource_type` (coloana de pe `imports`, migrată deja în Faza 1) → clasa
 * `ImportableResource` care știe s-o mapeze, valideze și scrie — mirror-ul lui
 * `App\Support\Bulk\BulkWritableResources`/`App\Support\Exports\ExportableResources`. Sursă
 * unică pentru controller, acțiuni și joburi: toate rezolvă resursa prin același nume.
 *
 * MVP (§14.1): Accounts, Contacts, Products, Variants — Deals și Orders rămân FR-IMP-03
 * (Faza 2/definitiv-amânat, complexitate relațională mai mare).
 */
final class ImportableResources
{
    /** @return array<string, class-string<ImportableResource>> */
    public static function map(): array
    {
        return [
            'accounts' => AccountImportResource::class,
            'contacts' => ContactImportResource::class,
            'products' => ProductImportResource::class,
            'variants' => VariantImportResource::class,
        ];
    }

    public static function resolve(string $resourceType): ImportableResource
    {
        $class = self::map()[$resourceType] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Resursa \"{$resourceType}\" nu are un import înregistrat.");
        }

        return app($class);
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::map());
    }
}
