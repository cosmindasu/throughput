<?php

namespace App\Support\SavedViews;

use App\Support\Lists\AccountList;
use App\Support\Lists\DealList;
use App\Support\Lists\ResourceList;
use InvalidArgumentException;

/**
 * Registrul (`resource_type` → listă/rută/coloane) pentru vizualizările salvate — plan §8:
 * „Aplicat mai întâi pe listele deja construite: Accounts, Deals". `SavedView::RESOURCE_TYPES`
 * (enum-ul din schema §15.1) e mai larg — orders/products/invoices intră aici abia când
 * fazele care le construiesc adaugă un rând, fără să schimbe restul clasei.
 *
 * `columns` — listele Accounts/Deals încă nu au selector de coloane: se construiește generic
 * în Faza 3, odată cu Orders/Products (plan §9, specs v1.18 §15.1, decizia proprietarului).
 * Coloana e totuși NOT NULL în schema Faza 1 (`saved_views.columns`), deci o vedere salvată
 * tot are nevoie de o valoare — nu o stare pe jumătate scrisă. Alegerea: coloanele EXACT
 * randate azi de `Accounts/Index.tsx`/`Deals/Index.tsx` (verificate acolo, nu presupuse), gata
 * pentru selectorul care le va citi/rescrie.
 */
final class SavedViewResourceType
{
    /**
     * @var array<string, array{list: class-string<ResourceList>, route: string, columns: list<string>}>
     */
    private const REGISTRY = [
        'accounts' => [
            'list' => AccountList::class,
            'route' => 'accounts.index',
            'columns' => ['name', 'owner', 'status', 'createdAt'],
        ],
        'deals' => [
            'list' => DealList::class,
            'route' => 'deals.index',
            'columns' => ['title', 'value', 'expectedCloseDate', 'account', 'owner', 'stage'],
        ],
    ];

    public static function isSupported(string $resourceType): bool
    {
        return array_key_exists($resourceType, self::REGISTRY);
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function list(string $resourceType): ResourceList
    {
        return app(self::definition($resourceType)['list']);
    }

    public static function routeName(string $resourceType): string
    {
        return self::definition($resourceType)['route'];
    }

    /** @return list<string> */
    public static function defaultColumns(string $resourceType): array
    {
        return self::definition($resourceType)['columns'];
    }

    /**
     * @return array{list: class-string<ResourceList>, route: string, columns: list<string>}
     */
    private static function definition(string $resourceType): array
    {
        return self::REGISTRY[$resourceType]
            ?? throw new InvalidArgumentException("Vizualizările salvate nu suportă încă resursa `{$resourceType}`.");
    }
}
