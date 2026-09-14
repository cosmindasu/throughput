<?php

namespace App\Actions\Stock;

use App\Actions\Stock\Concerns\LocksInventoryLevels;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * BR-STOCK-02 — inserează o mișcare în `stock_movements` și actualizează
 * `inventory_levels.on_hand` corespunzător, în **aceeași tranzacție de bază de date**,
 * niciodată în doi pași. Acoperă recepția (US-STOCK-01, `reason = receipt`) și
 * ajustarea manuală (`reason = adjustment`, notă obligatorie — BR-STOCK-01: orice
 * corecție e o mișcare nouă, niciodată un UPDATE/DELETE pe una existentă).
 *
 * Reutilizabilă: valul 2 (onorarea comenzilor, shipment-uri) va chema aceeași acțiune
 * pentru ieșirile de tip `sale`, fără s-o rescrie.
 */
final class RecordStockMovementAction
{
    use LocksInventoryLevels;

    public function execute(
        Variant $variant,
        Location $location,
        int $delta,
        string $reason,
        User $by,
        ?string $note = null,
        ?string $refType = null,
        ?string $refId = null,
    ): StockMovement {
        if ($delta === 0) {
            throw new InvalidArgumentException('A stock movement must have a non-zero delta.');
        }

        if (! in_array($reason, StockMovement::REASONS, true)) {
            throw new InvalidArgumentException("Unknown stock movement reason: {$reason}.");
        }

        // BR-STOCK-01, schema §10.2 — „Obligatoriu pentru reason = adjustment". Repetată
        // aici (a doua treaptă, ca la P2-001 pe alte pachete): `AdjustStockRequest` o
        // cere deja, dar acțiunea rămâne corectă chiar chemată din altă parte decât HTTP.
        if ($reason === StockMovement::REASON_ADJUSTMENT && trim((string) $note) === '') {
            throw new InvalidArgumentException('An adjustment requires a note explaining the correction.');
        }

        return DB::transaction(function () use ($variant, $location, $delta, $reason, $by, $note, $refType, $refId): StockMovement {
            $level = $this->lockLevels($variant->getKey(), [$location->getKey()])->get($location->getKey());

            // Stocul fizic nu coboară sub zero, oricare ar fi motivul mișcării: aceeași regulă
            // ca la transfer, verificată tot sub lock, fiindcă o mișcare concurentă poate schimba
            // `on_hand` între formular și acest moment. Un `on_hand` negativ ar da și un
            // `available` negativ, pe care BR-STOCK-04 îl exclude prin design.
            if ($level->on_hand + $delta < 0) {
                throw ValidationException::withMessages([
                    'delta' => "Only {$level->on_hand} on hand at this location; this change would take it below zero.",
                ]);
            }

            $level->on_hand += $delta;
            $level->save();

            $movement = new StockMovement([
                'variant_id' => $variant->getKey(),
                'location_id' => $location->getKey(),
                'delta' => $delta,
                'reason' => $reason,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'note' => $note,
            ]);
            $movement->created_by = $by->getKey();
            $movement->save();

            return $movement;
        });
    }
}
