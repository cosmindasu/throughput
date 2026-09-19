<?php

namespace App\Support\Orders;

use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;

/**
 * Faza 3, valul 2 (§11.2 pas 4) — cât mai poate fi expediat dintr-o linie de comandă
 * CHIAR ACUM: `quantity − quantity_fulfilled − cantitățile din shipment-urile DESCHISE
 * încă neexpediate` (task brief, item 2). Loc unic, citit de `CreateShipmentAction` (o
 * linie nouă nu poate depăși rămasul) și de `RetryShippingLabelAction` („re-verifică
 * rămasul" — US-ORD-03), ca cele două să nu recalculeze fiecare pe cont propriu și să
 * diveargă.
 *
 * „Deschis" = `Shipment::OPEN_STATUSES` (`label_pending`/`label_failed`/`label_purchased`):
 * un shipment deja `in_transit` și-a trecut deja cantitatea în `quantity_fulfilled`
 * (`MarkShipmentShippedAction`), deci ar număra de două ori dacă ar rămâne „deschis" aici.
 */
final class RemainingToShip
{
    /**
     * @param  string|null  $excludingShipmentId  exclude liniile ACESTUI shipment din suma
     *                                            „deschis" — folosit când se re-verifică un
     *                                            shipment existent (retry), ca propria lui
     *                                            cantitate să nu se scadă din ea însăși de
     *                                            două ori.
     */
    public static function forLine(OrderLine $line, ?string $excludingShipmentId = null): int
    {
        $line->loadMissing('shipmentLines.shipment');

        $openQuantity = $line->shipmentLines
            ->filter(function (ShipmentLine $shipmentLine) use ($excludingShipmentId): bool {
                if ($excludingShipmentId !== null && $shipmentLine->shipment_id === $excludingShipmentId) {
                    return false;
                }

                return in_array($shipmentLine->shipment->status, Shipment::OPEN_STATUSES, true);
            })
            ->sum('quantity');

        return $line->quantity - $line->quantity_fulfilled - (int) $openQuantity;
    }
}
