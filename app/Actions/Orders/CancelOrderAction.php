<?php

namespace App\Actions\Orders;

use App\Actions\Stock\Concerns\LocksInventoryLevels;
use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `draft|confirmed -> cancelled` (§11.3). `OrderPolicy::cancel()` verifică dreptul +
 * BR-ORD-01 (`shipments()->exists()`) ÎNAINTE de acțiune, FĂRĂ blocare — un shipment
 * creat concurent, DUPĂ verificarea Policy dar înainte ca această acțiune să apuce
 * `lockForUpdate()` de mai jos, ar trece neobservat. Code review P1 (reprodus cu două
 * sesiuni `psql`): `CreateShipmentAction` blochează ACEEAȘI comandă înainte de a insera
 * shipment-ul, deci a doua verificare de mai jos, SUB blocare, vede sigur orice shipment
 * apucat să existe — comanda nu poate ajunge `cancelled` cu un shipment agățat.
 */
final class CancelOrderAction
{
    use LocksInventoryLevels;

    public function execute(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.orders.cannot_cancel', ['status' => $locked->status->label()]),
                ]);
            }

            // Code review P1 — reverificare SUB blocare, nu doar în Policy. `Policy::cancel()`
            // a citit `shipments()->exists()` FĂRĂ blocare, într-o interogare separată de
            // această tranzacție: un `CreateShipmentAction` concurent, care blochează
            // ACEEAȘI comandă înainte de a insera shipment-ul, poate insera ÎNTRE acea
            // citire și acest `lockForUpdate()`. Fără reverificare aici, BR-ORD-01 s-ar
            // încălca în date (comandă `cancelled` cu shipment `label_pending` agățat),
            // iar `GenerateShippingLabelJob` ar cumpăra o etichetă pentru o comandă anulată.
            if ($locked->shipments()->exists()) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.orders.has_shipment'),
                ]);
            }

            if ($locked->status === OrderStatus::Confirmed) {
                $lines = $locked->orderLines()->orderBy('variant_id')->get();
                $variantIds = $lines->pluck('variant_id')->unique()->values();

                if ($variantIds->isNotEmpty()) {
                    // Aceeași locație implicită folosită de `ConfirmOrderAction` la
                    // rezervare (§9 task — simplificare asumată, o singură locație de
                    // rezervare în această fază) și aceeași convenție de blocare
                    // (code review P1-003, `LocksInventoryLevels::lockLevelsAtLocation()`):
                    // creează întâi, sortat, orice rând `inventory_levels` lipsă pentru
                    // variantele cerute, apoi le blochează într-o singură interogare,
                    // ordonată `variant_id, location_id`, înainte de orice scriere pe
                    // `reserved`.
                    // Tiebreaker pe `id` (DOM-05, audit 2026-09-23) — `.ai/rules/tenancy.md`:
                    // `created_at` are precizie 0, deci „cel mai vechi" nu e determinist singur.
                    $location = Location::query()->where('is_default', true)->first()
                        ?? Location::query()->oldest('created_at')->oldest('id')->firstOrFail();

                    $levels = $this->lockLevelsAtLocation($location->getKey(), $variantIds->all());

                    foreach ($lines as $line) {
                        // BR-ORD-01 a fost deja verificată de Policy (fără shipment),
                        // deci `quantity_fulfilled` e mereu 0 aici — eliberarea e
                        // integrală, pe toată cantitatea liniei. Două linii duplicate pe
                        // aceeași variantă eliberează fiecare propriul `$remaining`, pe
                        // ACELAȘI rând `inventory_levels` (`keyBy` de mai jos, în trait).
                        $remaining = $line->quantity - $line->quantity_fulfilled;
                        $level = $levels->get($line->variant_id);

                        // Clamp la 0 (code review P1-003) — `reserved` nu coboară sub
                        // zero, oricât ar cere eliberarea acestei linii. Nu e un caz
                        // așteptat pe date corecte (o comandă confirmată a rezervat deja
                        // `$remaining` la confirmare), dar un rând abia creat aici de
                        // `lockLevelsAtLocation()` (variantă fără proiecție încă) ar
                        // porni de la `reserved = 0`, iar o eliberare necondiționată l-ar
                        // trimite negativ.
                        $decrement = min($remaining, $level->reserved);

                        if ($decrement > 0) {
                            $level->decrement('reserved', $decrement);
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
