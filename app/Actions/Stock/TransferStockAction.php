<?php

namespace App\Actions\Stock;

use App\Actions\Stock\Concerns\LocksInventoryLevels;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * BR-STOCK-03, US-STOCK-03 — un transfer între locații scrie **două** mișcări legate
 * prin același `ref_id` (`reason = transfer`), într-o singură tranzacție: una negativă
 * la sursă, una pozitivă la destinație. `ref_type = 'transfer'`, `ref_id` e generat aici
 * (nu există un document sursă separat, spre deosebire de `order`/`shipment`/`import`).
 *
 * Ambele rânduri `inventory_levels` (sursă + destinație) se blochează într-o SINGURĂ
 * interogare ordonată `ORDER BY variant_id, location_id` — vezi `LocksInventoryLevels`
 * — nu în două `lockForUpdate()` succesive, care ar putea intra în conflict cu un
 * transfer concurent în sens invers pe aceeași pereche de locații.
 */
final class TransferStockAction
{
    use LocksInventoryLevels;

    /**
     * @return array{from: StockMovement, to: StockMovement}
     */
    public function execute(
        Variant $variant,
        Location $from,
        Location $to,
        int $quantity,
        User $by,
        ?string $note = null,
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A transfer quantity must be positive.');
        }

        if ($from->getKey() === $to->getKey()) {
            throw new InvalidArgumentException('Source and destination locations must differ.');
        }

        return DB::transaction(function () use ($variant, $from, $to, $quantity, $by, $note): array {
            $levels = $this->lockLevels($variant->getKey(), [$from->getKey(), $to->getKey()]);
            $sourceLevel = $levels->get($from->getKey());
            $destinationLevel = $levels->get($to->getKey());

            // Reverificat SUB lock (la fel ca `MoveDealStageAction`): un transfer
            // concurent poate fi golit stocul sursă între validarea din FormRequest și
            // acest moment. Eroare de STARE (422), nu de drept — mesajul ajunge pe câmp.
            if ($sourceLevel->on_hand < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$sourceLevel->on_hand} on hand at the source location.",
                ]);
            }

            $refId = (string) Str::ulid();

            $sourceLevel->on_hand -= $quantity;
            $sourceLevel->save();

            $destinationLevel->on_hand += $quantity;
            $destinationLevel->save();

            $fromMovement = new StockMovement([
                'variant_id' => $variant->getKey(),
                'location_id' => $from->getKey(),
                'delta' => -$quantity,
                'reason' => StockMovement::REASON_TRANSFER,
                'ref_type' => 'transfer',
                'ref_id' => $refId,
                'note' => $note,
            ]);
            $fromMovement->created_by = $by->getKey();
            $fromMovement->save();

            $toMovement = new StockMovement([
                'variant_id' => $variant->getKey(),
                'location_id' => $to->getKey(),
                'delta' => $quantity,
                'reason' => StockMovement::REASON_TRANSFER,
                'ref_type' => 'transfer',
                'ref_id' => $refId,
                'note' => $note,
            ]);
            $toMovement->created_by = $by->getKey();
            $toMovement->save();

            return ['from' => $fromMovement, 'to' => $toMovement];
        });
    }
}
