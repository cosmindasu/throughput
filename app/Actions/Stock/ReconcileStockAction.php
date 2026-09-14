<?php

namespace App\Actions\Stock;

use App\Models\InventoryLevel;
use App\Models\StockMovement;

/**
 * FR-STOCK-01, §10.4 — recalculează `on_hand` pentru fiecare `(variant_id, location_id)`
 * direct din suma `stock_movements.delta` și o compară cu proiecția materializată din
 * `inventory_levels`. **Nu corectează** — orice divergență e raportată, corecția rămâne
 * o decizie umană (o mișcare nouă de tip `adjustment`, BR-STOCK-01).
 *
 * Rulează în contextul unui SINGUR tenant (apelantul — `stock:reconcile` — deschide
 * contextul cu `TenantContext::run()` per tenant, ADR-014 pct. 4): global scope-ul și
 * RLS scopează deja ambele interogări de mai jos, fără niciun `where tenant_id` explicit
 * aici.
 */
final class ReconcileStockAction
{
    /**
     * @return array{checked: int, divergences: list<array{variant_id: string, location_id: string, projected_on_hand: int, calculated_on_hand: int}>}
     */
    public function execute(): array
    {
        $calculated = StockMovement::query()
            ->selectRaw('variant_id, location_id, sum(delta) as calculated_on_hand')
            ->groupBy('variant_id', 'location_id')
            ->get()
            ->keyBy(fn ($row) => "{$row->variant_id}|{$row->location_id}");

        $projected = InventoryLevel::query()
            ->get(['variant_id', 'location_id', 'on_hand'])
            ->keyBy(fn (InventoryLevel $level) => "{$level->variant_id}|{$level->location_id}");

        // Uniunea celor două chei: o pereche poate exista într-o singură parte —
        // mișcări scrise fără proiecție (bug de scriere în doi pași) sau o proiecție
        // fără nicio mișcare în spate (bug și mai grav: `on_hand` scris direct).
        $pairs = $calculated->keys()->merge($projected->keys())->unique();

        $divergences = [];

        foreach ($pairs as $pair) {
            [$variantId, $locationId] = explode('|', $pair, 2);

            $calculatedOnHand = (int) ($calculated->get($pair)->calculated_on_hand ?? 0);
            $projectedOnHand = (int) ($projected->get($pair)->on_hand ?? 0);

            if ($calculatedOnHand !== $projectedOnHand) {
                $divergences[] = [
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'projected_on_hand' => $projectedOnHand,
                    'calculated_on_hand' => $calculatedOnHand,
                ];
            }
        }

        return ['checked' => $pairs->count(), 'divergences' => $divergences];
    }
}
