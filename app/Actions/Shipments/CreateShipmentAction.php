<?php

namespace App\Actions\Shipments;

use App\Enums\OrderStatus;
use App\Jobs\Shipping\GenerateShippingLabelJob;
use App\Models\Location;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Shipment;
use App\Services\Shipping\CarrierResolver;
use App\Support\Orders\RemainingToShip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §11.2 pas 4, US-ORD-02/03 — „Create shipment" dintr-o comandă `confirmed` sau
 * `partially_fulfilled`: linii + cantități alese (parțial permis), cantitatea per linie
 * ≤ rămasul neexpediat (`App\Support\Orders\RemainingToShip`).
 *
 * Locația = locația implicită a tenantului, ACEEAȘI convenție folosită de
 * `ConfirmOrderAction` la rezervare (specs v1.19, §11.2 pas 3) — `shipments.location_id`
 * există în schemă, dar `order_lines` nu are propria locație, deci nu există de unde
 * alege alta.
 *
 * ADR-013 — NICIUN apel de curierat aici: shipment-ul se salvează `label_pending`, în
 * tranzacția scurtă de mai jos (doar scrieri proprii), iar `GenerateShippingLabelJob`
 * se pune în coadă DUPĂ commit (conexiunea `redis`, `after_commit = true`, config/queue.php).
 */
final class CreateShipmentAction
{
    public function __construct(private readonly CarrierResolver $carrierResolver) {}

    /**
     * @param  array<string, int>  $quantities  order_line_id => cantitate de inclus
     */
    public function execute(Order $order, array $quantities): Shipment
    {
        $shipment = DB::transaction(function () use ($order, $quantities): Shipment {
            // Rândul pe care chiar îl modificăm (comanda la tranziția ei de onorare, nu
            // părintele tenant) — `lockForUpdate()` e acceptabil aici (`.ai/rules/tenancy.md`):
            // oprește doar copiii ACESTEI comenzi (alte linii/shipment-uri concurente pe
            // ACELAȘI order), nu restul tenantului. Serializează două „Create shipment"
            // simultane pe aceeași comandă, care altfel ar putea citi același „rămas" și
            // supra-angaja aceeași cantitate neexpediată.
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Confirmed, OrderStatus::PartiallyFulfilled], true)) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.shipments.invalid_status_for_creation', ['status' => $locked->status->label()]),
                ]);
            }

            $requested = array_filter(
                array_map(static fn ($qty): int => (int) $qty, $quantities),
                static fn (int $qty): bool => $qty > 0,
            );

            if ($requested === []) {
                throw ValidationException::withMessages([
                    'lines' => trans('rules.shipments.choose_quantity'),
                ]);
            }

            $lines = $locked->orderLines()
                ->whereIn('id', array_keys($requested))
                ->with('shipmentLines.shipment')
                ->get()
                ->keyBy('id');

            $location = Location::query()->where('is_default', true)->first()
                ?? Location::query()->oldest('created_at')->firstOrFail();

            $shipment = new Shipment([
                'order_id' => $locked->getKey(),
                'location_id' => $location->getKey(),
                'carrier' => $this->carrierResolver->activeProvider(),
                'status' => Shipment::STATUS_LABEL_PENDING,
            ]);
            $shipment->save();

            foreach ($requested as $orderLineId => $quantity) {
                $line = $lines->get($orderLineId);

                if ($line === null) {
                    throw ValidationException::withMessages([
                        'lines' => trans('rules.shipments.line_not_in_order'),
                    ]);
                }

                $remaining = RemainingToShip::forLine($line);

                if ($quantity > $remaining) {
                    throw ValidationException::withMessages([
                        "lines.{$orderLineId}" => trans_choice('rules.shipments.remaining_to_ship', $remaining),
                    ]);
                }

                $shipment->shipmentLines()->create([
                    'order_line_id' => $orderLineId,
                    'quantity' => $quantity,
                ]);
            }

            return $shipment;
        });

        GenerateShippingLabelJob::dispatch(TenantScope::requireCurrentTenantId(), $shipment->getKey());

        return $shipment->fresh(['shipmentLines.orderLine', 'location']);
    }
}
