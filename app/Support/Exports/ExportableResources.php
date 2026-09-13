<?php

namespace App\Support\Exports;

use App\Support\Lists\AccountList;
use App\Support\Lists\ContactList;
use App\Support\Lists\ResourceList;
use InvalidArgumentException;

/**
 * Harta `resource_type` (coloana de pe `bulk_operations`) → clasa `ResourceList` care știe
 * s-o interogheze și s-o exporte (§13.2). Sursă unică pentru declanșarea exportului
 * (`ListExport`) și pentru `ExportListJob` (exportul în coadă) — amândoi rezolvă lista prin
 * același nume, deci nu pot ajunge să interogheze lucruri diferite pentru aceeași operație.
 *
 * Celelalte resurse din specs.md §13.5 (Comenzi, Produse, Facturi) își adaugă câte o linie
 * aici, în fazele care le construiesc.
 */
final class ExportableResources
{
    /** @return array<string, class-string<ResourceList>> */
    public static function map(): array
    {
        return [
            'accounts' => AccountList::class,
            'contacts' => ContactList::class,
        ];
    }

    public static function resolve(string $resourceType): ResourceList&ExportableList
    {
        $class = self::map()[$resourceType] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Resursa \"{$resourceType}\" nu are o listă exportabilă înregistrată.");
        }

        $list = app($class);

        if (! $list instanceof ExportableList) {
            throw new InvalidArgumentException($class.' trebuie să implementeze '.ExportableList::class.' ca să fie exportabilă.');
        }

        return $list;
    }
}
