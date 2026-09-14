<?php

namespace App\Actions\Stock\Concerns;

use App\Models\InventoryLevel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

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
     * Creează, în afara lock-ului, rândurile `(variant_id, location_id)` care nu există
     * încă — seed-ul (Faza 1) nu garantează o proiecție pentru fiecare pereche variantă/
     * locație, doar pentru cele efectiv mișcate. Inserările sunt individuale (un rând per
     * `INSERT`), deci nu pot produce ele însele un deadlock între cele două locuri care
     * scriu pe acest tabel; constrângerea unică `(tenant_id, variant_id, location_id)`
     * absoarbe o cursă rară cu o a doua interogare, nu cu o excepție netratată.
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

        foreach (array_diff($locationIds, $existing) as $locationId) {
            try {
                InventoryLevel::query()->create([
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'on_hand' => 0,
                    'reserved' => 0,
                ]);
            } catch (QueryException) {
                // Rândul a fost creat concurent de cealaltă parte a convenției de mai
                // sus (ex. o rezervare de comandă) între verificarea și inserarea de aici
                // — constrângerea unică a prins-o, rândul deja există, nimic de făcut.
            }
        }
    }
}
