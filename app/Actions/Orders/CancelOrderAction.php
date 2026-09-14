<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `draft|confirmed -> cancelled` (§11.3). `OrderPolicy::cancel()` a verificat deja
 * dreptul + BR-ORD-01 (`shipments()->exists()`); acest cod verifică STAREA (poate
 * ACEASTĂ comandă, ACUM, să tranziționeze la `cancelled`) și face efectul: eliberează
 * `reserved` dacă exista (adică dacă era `confirmed`), fără nicio mișcare de stoc —
 * fizic nimic n-a părăsit depozitul (§10.5, §11.3).
 */
final class CancelOrderAction
{
    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw ValidationException::withMessages([
                    'status' => "This order can't be cancelled from its current status ({$locked->status->label()}).",
                ]);
            }

            if ($locked->status === OrderStatus::Confirmed) {
                $lines = $locked->orderLines()->orderBy('variant_id')->get();
                $variantIds = $lines->pluck('variant_id')->unique()->values();

                if ($variantIds->isNotEmpty()) {
                    // Aceeași locație implicită folosită de `ConfirmOrderAction` la
                    // rezervare (§9 task — simplificare asumată, o singură locație de
                    // rezervare în această fază) și aceeași convenție de blocare: o
                    // singură interogare, ordonată `variant_id, location_id`, înainte
                    // de orice scriere pe `reserved`.
                    $location = Location::query()->where('is_default', true)->first()
                        ?? Location::query()->oldest('created_at')->firstOrFail();

                    $levels = InventoryLevel::query()
                        ->where('location_id', $location->getKey())
                        ->whereIn('variant_id', $variantIds)
                        ->orderBy('variant_id')
                        ->orderBy('location_id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('variant_id');

                    foreach ($lines as $line) {
                        // BR-ORD-01 a fost deja verificată de Policy (fără shipment),
                        // deci `quantity_fulfilled` e mereu 0 aici — eliberarea e
                        // integrală, pe toată cantitatea liniei.
                        $remaining = $line->quantity - $line->quantity_fulfilled;
                        $level = $levels->get($line->variant_id);

                        if ($remaining > 0 && $level !== null) {
                            $level->decrement('reserved', $remaining);
                        }
                    }
                }
            }

            $locked->status = OrderStatus::Cancelled;
            $locked->save();

            return $locked->fresh(['account', 'contact', 'owner', 'orderLines.variant']);
        });
    }
}
