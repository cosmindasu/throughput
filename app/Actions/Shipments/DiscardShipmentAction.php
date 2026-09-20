<?php

namespace App\Actions\Shipments;

use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task brief, item 3 — „renunțarea la un shipment `label_failed` care eliberează
 * cantitățile". Niciun shipment CREAT (indiferent de status) nu a atins vreodată
 * `inventory_levels.reserved` sau `on_hand` — asta se întâmplă abia la
 * `MarkShipmentShippedAction`. Deci „eliberarea" nu e o mișcare de stoc: e ștergerea
 * rândului, care scoate liniile lui din `Shipment::OPEN_STATUSES`
 * (`App\Support\Orders\RemainingToShip`) — rămasul de expediat al liniilor redevine
 * disponibil pentru un shipment nou. `shipment_lines` cascadează
 * (`shipment_lines.shipment_id` → `cascadeOnDelete()`, migrația tabelei).
 */
final class DiscardShipmentAction
{
    public function execute(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment): void {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Shipment::STATUS_LABEL_FAILED) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.shipments.discard_requires_label_failed', ['status' => $locked->status]),
                ]);
            }

            $locked->delete();
        });
    }
}
