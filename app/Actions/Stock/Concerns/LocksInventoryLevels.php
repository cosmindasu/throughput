<?php

namespace App\Actions\Stock\Concerns;

use App\Models\InventoryLevel;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Convenția de blocare pe stoc, comună cu lotul Comenzi (task brief): orice cod care
 * blochează rânduri `inventory_levels` o face cu `lockForUpdate()`, într-o singură
 * interogare ordonată `ORDER BY variant_id, location_id`, înainte de orice scriere —
 * ca lotul acesta (scrie `on_hand`) și lotul Comenzi (scrie `reserved`) să nu se poată
 * bloca reciproc (deadlock) când ating aceleași rânduri în ordine diferită.
 *
 * `RecordStockMovementAction` (un rând) și `TransferStockAction` (două rânduri, aceeași
 * variantă, locații diferite) trec amândouă prin `lockLevels()`.
 */
trait LocksInventoryLevels
{
    /**
     * @param  list<string>  $locationIds
     * @return Collection<string, InventoryLevel> cheie = location_id
     */
    private function lockLevels(string $variantId, array $locationIds): Collection
    {
        $this->ensureLevelsExist($variantId, $locationIds);

        return InventoryLevel::query()
            ->where('variant_id', $variantId)
            ->whereIn('location_id', $locationIds)
            ->orderBy('variant_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('location_id');
    }

    /**
     * Creează rândurile `(variant_id, location_id)` care lipsesc, înainte de blocare:
     * seed-ul (Faza 1) nu garantează o proiecție pentru fiecare pereche variantă/locație,
     * doar pentru cele efectiv mișcate.
     *
     * Două reguli, fiecare pentru un defect reprodus cu două sesiuni `psql`:
     *  - Rândurile se inserează sortate, într-o singură instrucțiune. Inserate în ordinea
     *    apelantului, două transferuri concurente în sensuri opuse pe o pereche fără
     *    proiecție ajungeau să țină fiecare câte un rând nou și să-l aștepte pe al
     *    celuilalt: deadlock.
     *  - `INSERT … ON CONFLICT DO NOTHING`, nu `create()` într-un `catch`. În PostgreSQL
     *    orice eroare abortează tranzacția, iar aici tranzacția e a întregii cereri (ADR-013):
     *    rândul creat concurent dădea 500 la interogarea următoare, nu „nimic de făcut".
     *
     * `insertOrIgnore` ocolește evenimentele Eloquent, deci `id` și `tenant_id` se
     * completează explicit, cum le-ar fi completat `HasUlids` și `BelongsToTenant`.
     *
     * @param  list<string>  $locationIds
     */
    private function ensureLevelsExist(string $variantId, array $locationIds): void
    {
        $existing = InventoryLevel::query()
            ->where('variant_id', $variantId)
            ->whereIn('location_id', $locationIds)
            ->pluck('location_id')
            ->all();

        $missing = array_values(array_diff($locationIds, $existing));

        if ($missing === []) {
            return;
        }

        sort($missing, SORT_STRING);
        $tenantId = TenantScope::requireCurrentTenantId();
        $now = now();

        InventoryLevel::query()->insertOrIgnore(array_map(fn (string $locationId): array => [
            'id' => strtolower((string) Str::ulid()),
            'tenant_id' => $tenantId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'on_hand' => 0,
            'reserved' => 0,
            'updated_at' => $now,
        ], $missing));
    }
}
