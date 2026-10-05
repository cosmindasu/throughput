<?php

namespace App\Support\Stock;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;

/**
 * FR-STOCK-02 (specs.md §10.7) — regula de „low stock", într-un SINGUR loc conceptual:
 * o variantă ACTIVĂ, cu un prag NENUL, al cărei `available` (suma pe toate locațiile a
 * `on_hand − reserved`, §10.5) e STRICT mai mic decât el. `null` = fără alertă, niciodată
 * „low" prin comparație implicită cu 0 — la fel ca `App\Support\Bulk\BulkConfirmationThreshold`,
 * sursă unică citită din mai multe locuri.
 *
 * O variantă INACTIVĂ nu intră niciodată în alertă (decizie de produs, code review Faza 3
 * valul 2): nu mai e recomandată la aprovizionare, deci un „Low stock" pe ea ar fi zgomot,
 * nu un semnal acționabil. `variantsCount`/`variants_count` NU se schimbă — doar regula de
 * „low", nu numărătoarea brută de variante.
 *
 * Regula are DOUĂ forme, nu una, pentru că apare în două contexte cu costuri diferite:
 *
 * - `isLow()` evaluează în PHP, pe un `Variant` cu `inventoryLevels` deja încărcată
 *   (`Products/Show`, `Stock/Show` — un produs/o variantă, N mic, fără interogare
 *   suplimentară per rând).
 * - `lowVariantsCountSubquery()` exprimă ACEEAȘI comparație direct în SQL, corelată pe
 *   `variants.product_id = products.id`, pentru `ProductList::baseQuery()`: acolo
 *   `lowStockVariantsCount` se calculează PE RÂND DE PRODUS, într-o singură interogare
 *   agregată — fără N+1, fără încărcarea variantelor în PHP (task brief). PostgreSQL e
 *   singurul RDBMS al proiectului (`.ai/rules/project.md`), deci SQL brut e la fel de
 *   acceptat aici ca în `EnablesRowLevelSecurity::matchesSetting()`.
 */
final class LowStockRule
{
    public static function isLow(?int $threshold, int $available, bool $isActive): bool
    {
        return $isActive && $threshold !== null && $available < $threshold;
    }

    /**
     * Variantele ACTIVE aflate sub pragul PROPRIU, cu `available` deja calculat și cele mai
     * urgente întâi — a TREIA formă a aceleiași reguli, pentru contextele care au nevoie de
     * LISTĂ sau de NUMĂR pe tot tenantul, nu per produs (dashboard: placa „Low stock alerts"
     * și lista din „Needs attention" de sub ea).
     *
     * Exista fiindcă dashboard-ul inventase o a patra definiție, în afara acestui fișier:
     * `inventory_levels` cu `(on_hand - reserved) <= 5`. Diferă de regulă pe patru axe
     * deodată — prag fix în loc de cel al variantei, pe RÂND DE LOCAȚIE (deci o variantă
     * ținută în trei depozite se număra de trei ori), fără condiția `is_active`, și cu `<=`
     * în loc de `<`. Trecea neobservată cât timp placa arăta doar o cifră; lista de sub ea
     * pune numele lângă număr, unde contradicția cu pagina de produs devine verificabilă.
     *
     * `->count()` pe builderul ăsta e sigur: `Builder::setAggregate()` șterge ordonarea când
     * nu există `GROUP BY`, deci subinterogarea din `ORDER BY` nu ajunge lângă agregat.
     */
    public static function lowVariants(): Builder
    {
        return Variant::query()
            ->select('variants.*')
            ->selectRaw(self::availableSql().' as available')
            ->where('variants.is_active', true)
            ->whereNotNull('variants.low_stock_threshold')
            ->whereRaw(self::availableSql().' < variants.low_stock_threshold')
            ->orderByRaw(self::availableSql());
    }

    /**
     * Subinterogare scalară: numărul de variante ACTIVE „low" ale unui produs. De folosit
     * DOAR pe o interogare care are deja `products` ca FROM (`whereColumn` se leagă de
     * `products.id` din query-ul exterior) — vezi `ProductList::baseQuery()`.
     */
    public static function lowVariantsCountSubquery(): Builder
    {
        return Variant::query()
            ->selectRaw('count(*)')
            ->whereColumn('variants.product_id', 'products.id')
            ->where('variants.is_active', true)
            ->whereNotNull('variants.low_stock_threshold')
            ->whereRaw(self::availableSql().' < variants.low_stock_threshold');
    }

    /**
     * `available` agregat pe toate locațiile unei variante (§10.5): SUM(on_hand) −
     * SUM(reserved), corelat pe `inventory_levels.variant_id = variants.id`. Indexul unic
     * `(tenant_id, variant_id, location_id)` (migrația de creare `inventory_levels`) — cu
     * RLS adăugând `tenant_id = ?` pe aceeași interogare — face din asta un Index Scan pe
     * prefixul `(tenant_id, variant_id)`, nu un Seq Scan.
     */
    private static function availableSql(): string
    {
        return '(select coalesce(sum(inventory_levels.on_hand - inventory_levels.reserved), 0)
                  from inventory_levels where inventory_levels.variant_id = variants.id)';
    }
}
