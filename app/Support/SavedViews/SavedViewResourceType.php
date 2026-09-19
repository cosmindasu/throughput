<?php

namespace App\Support\SavedViews;

use App\Support\Lists\AccountList;
use App\Support\Lists\DealList;
use App\Support\Lists\OrderList;
use App\Support\Lists\ProductList;
use App\Support\Lists\ResourceList;
use InvalidArgumentException;

/**
 * Registrul (`resource_type` → listă/rută/coloane) pentru vizualizările salvate — plan §8:
 * „Aplicat mai întâi pe listele deja construite: Accounts, Deals". `SavedView::RESOURCE_TYPES`
 * (enum-ul din schema §15.1) e mai larg — `contacts`/`invoices` intră aici abia când fazele
 * care le construiesc adaugă un rând, fără să schimbe restul clasei.
 *
 * `columns` — selectorul generic de coloane (plan §9, specs.md §15.1, mutat în Faza 3).
 * Lista NU include coloana de identificare a resursei (numele/titlul/numărul, cu linkul
 * spre înregistrare): aceea rămâne mereu vizibilă, în afara setului configurabil, la fel ca
 * checkbox-ul de bulk și coloana de acțiuni — pagina o randează fix, nu prin `columns`.
 * `defaultColumns` e un SUBSET (în ordine) din `columns`: ce vede un utilizator care n-a
 * atins niciodată selectorul. Pentru Accounts/Deals, `defaultColumns` == `columns` (coloanele
 * EXACT randate azi, verificate în `Accounts/Index.tsx`/`Deals/Index.tsx` — nimic de ascuns,
 * exact motivul din specs.md v1.18 pentru care selectorul nu s-a construit în Faza 2). Pentru
 * Orders, `defaultColumns` reproduce randarea de dinaintea selectorului (verificată în
 * `Orders/Index.tsx`, nu presupusă). Pentru Products, `defaultColumns` adaugă `lowStock` peste
 * randarea de dinainte — singura excepție de la „implicit = ce se vedea deja": FR-STOCK-02
 * cere alerta de stoc scăzut vizibilă fără acțiune din partea utilizatorului, nu doar opt-in.
 */
final class SavedViewResourceType
{
    /**
     * @var array<string, array{list: class-string<ResourceList>, route: string, columns: list<string>, defaultColumns: list<string>}>
     */
    private const REGISTRY = [
        'accounts' => [
            'list' => AccountList::class,
            'route' => 'accounts.index',
            'columns' => ['owner', 'status', 'createdAt'],
            'defaultColumns' => ['owner', 'status', 'createdAt'],
        ],
        'deals' => [
            'list' => DealList::class,
            'route' => 'deals.index',
            'columns' => ['value', 'expectedCloseDate', 'createdAt', 'account', 'owner', 'stage'],
            'defaultColumns' => ['value', 'expectedCloseDate', 'createdAt', 'account', 'owner', 'stage'],
        ],
        // Orders/Products — plan §9 („Selector de coloane"), înregistrate acum (lista permisă +
        // implicită), cablate pe `Orders/Index.tsx`/`Products/Index.tsx` în pasul D2.
        'orders' => [
            'list' => OrderList::class,
            'route' => 'orders.index',
            'columns' => ['status', 'account', 'owner', 'grandTotal', 'placedAt', 'createdAt'],
            'defaultColumns' => ['grandTotal', 'placedAt', 'createdAt', 'status', 'account', 'owner'],
        ],
        'products' => [
            'list' => ProductList::class,
            'route' => 'products.index',
            // `lowStock` — `ProductResource::lowStockVariantsCount` (lotul F). E și PERMISĂ,
            // și IMPLICITĂ (D2, decizia proprietarului): FR-STOCK-02 cere alerta vizibilă pe
            // listă, nu doar disponibilă pentru cine caută selectorul — „N low" trebuie să se
            // vadă fără nicio acțiune din partea utilizatorului.
            'columns' => ['category', 'variantsCount', 'isActive', 'createdAt', 'lowStock'],
            'defaultColumns' => ['category', 'variantsCount', 'isActive', 'lowStock'],
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

    /**
     * Coloanele PERMISE (configurabile) ale resursei, în ordinea canonică folosită de
     * meniul selectorului — exclude identitatea/bulk/acțiuni, mereu fixe pe pagină.
     *
     * @return list<string>
     */
    public static function permittedColumns(string $resourceType): array
    {
        return self::definition($resourceType)['columns'];
    }

    /** @return list<string> */
    public static function defaultColumns(string $resourceType): array
    {
        return self::definition($resourceType)['defaultColumns'];
    }

    /**
     * @return array{list: class-string<ResourceList>, route: string, columns: list<string>, defaultColumns: list<string>}
     */
    private static function definition(string $resourceType): array
    {
        return self::REGISTRY[$resourceType]
            ?? throw new InvalidArgumentException("Vizualizările salvate nu suportă încă resursa `{$resourceType}`.");
    }
}
