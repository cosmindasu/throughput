<?php

namespace App\Actions\Shipments;

use App\Jobs\Shipping\GenerateShippingLabelJob;
use App\Models\Scopes\TenantScope;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-ORD-03 — „Reîncercare manuală din UI pentru `label_failed`". Re-pune shipment-ul pe
 * `label_pending` și redispecerizează `GenerateShippingLabelJob`, exact fluxul de la
 * creare (ADR-013 — jobul, nu cererea HTTP, face apelul de curierat).
 *
 * „Re-verifică rămasul" (task brief): înainte de reîncercare, se verifică din nou că
 * angajamentele curente ale fiecărei linii (onorat + toate shipment-urile deschise,
 * INCLUSIV acesta) nu depășesc cantitatea comandată. Sub invariantul păzit de
 * `CreateShipmentAction` la fiecare creare, asta nu ar trebui să pice niciodată — dar
 * verificarea rămâne aici explicit, ca un refuz clar, nu o eticheta cumpărată peste o
 * stare deja inconsistentă.
 */
final class RetryShippingLabelAction
{
    public function execute(Shipment $shipment): Shipment
    {
        $shipment = DB::transaction(function () use ($shipment): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Shipment::STATUS_LABEL_FAILED) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.shipments.retry_requires_label_failed', ['status' => $locked->status]),
                ]);
            }

            $lines = $locked->shipmentLines()->with('orderLine.shipmentLines.shipment')->get();

            foreach ($lines as $line) {
                $orderLine = $line->orderLine;
                $committed = $orderLine->quantity_fulfilled + $orderLine->shipmentLines
                    ->filter(fn ($sl) => in_array($sl->shipment->status, Shipment::OPEN_STATUSES, true))
                    ->sum('quantity');

                if ($committed > $orderLine->quantity) {
                    throw ValidationException::withMessages([
                        'lines' => trans('rules.shipments.line_no_room'),
                    ]);
                }
            }

            $locked->status = Shipment::STATUS_LABEL_PENDING;
            $locked->error_message = null;
            $locked->save();

            return $locked;
        });

        GenerateShippingLabelJob::dispatch(TenantScope::requireCurrentTenantId(), $shipment->getKey());

        return $shipment;
    }
}
