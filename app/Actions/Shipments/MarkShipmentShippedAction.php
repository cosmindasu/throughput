<?php

namespace App\Actions\Shipments;

use App\Actions\Stock\Concerns\LocksInventoryLevels;
use App\Actions\Stock\RecordStockMovementAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §11.2 pas 5-6, §11.3 — marcarea „shipped" a unui shipment `label_purchased`: fizic
 * pleacă din depozit ACUM, nu la crearea shipment-ului (ADR-004, §10.5 — „reserved" e o
 * alocare, nu un eveniment fizic).
 *
 * Ordinea blocărilor, într-o SINGURĂ tranzacție (task brief, item 4):
 *  1. Comanda ÎNTÂI (`lockForUpdate()`, rândul pe care chiar îl modificăm — acceptabil
 *     per `.ai/rules/tenancy.md`, oprește doar copiii ACESTEI comenzi). Serializează două
 *     marcări concurente pe shipment-uri DIFERITE ale ACELEIAȘI comenzi: ambele scriu
 *     `orders.status`/`order_lines.quantity_fulfilled`, deci a doua așteaptă commit-ul
 *     primei înainte să recalculeze starea comenzii pe date proaspete.
 *  2. Shipment-ul însuși (rândul pe care îl tranziționăm).
 *  3. Rândurile `inventory_levels`, într-o SINGURĂ interogare sortată `variant_id,
 *     location_id` (`LocksInventoryLevels::lockLevelsAtLocation()` — o singură locație,
 *     cea a shipment-ului), cu cererea agregată PE VARIANTĂ (două linii pe aceeași
 *     variantă se adună înainte de verificarea `on_hand`, ca la `ConfirmOrderAction`
 *     P1-002) — ASTA înainte de orice scriere.
 *
 * `RecordStockMovementAction` e chemată o dată per variantă, DUPĂ verificarea de mai sus:
 * are propria ei tranzacție (SAVEPOINT, fiindcă suntem deja într-una) și propriul
 * `lockLevels()`, dar pe rânduri deja blocate de ACEEAȘI conexiune/tranzacție —
 * re-cererea aceluiași lock e un no-op (PostgreSQL nu se auto-blochează), deci ordinea
 * sortată stabilită la pasul 3 rămâne intactă; ea NU introduce o blocare nouă, doar
 * confirmă una deja deținută. `reserved` scade separat, tot sub același lock, cu
 * `decrement()` (SQL `SET reserved = reserved - ?`, nu un `save()` care ar putea
 * suprascrie `on_hand`-ul proaspăt scris de `RecordStockMovementAction` cu o valoare
 * stale din obiectul PHP local).
 *
 * `on_hand` insuficient (backorder nerecepționat) → `ValidationException`, ARUNCATĂ
 * ÎNAINTE de orice scriere (verificarea e un pas separat, complet, peste TOATE variantele,
 * înainte de bucla care scrie) — nimic parțial.
 */
final class MarkShipmentShippedAction
{
    use LocksInventoryLevels;

    public function __construct(private readonly RecordStockMovementAction $recordStockMovement) {}

    public function execute(Shipment $shipment, User $actor): Shipment
    {
        return DB::transaction(function () use ($shipment, $actor): Shipment {
            /** @var Order $order */
            $order = Order::query()->whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();

            /** @var Shipment $locked */
            $locked = Shipment::query()->whereKey($shipment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Shipment::STATUS_LABEL_PURCHASED) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.shipments.mark_shipped_requires_label_purchased', ['status' => $locked->status]),
                ]);
            }

            $lines = $locked->shipmentLines()->with('orderLine')->orderBy('order_line_id')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => trans('rules.shipments.no_lines'),
                ]);
            }

            // BR-STOCK-04 / P1-002 — agregat PE VARIANTĂ: două linii de comandă pe aceeași
            // variantă cer împreună o singură verificare de `on_hand`, nu două separate care
            // ar putea trece individual dar depăși împreună.
            $quantityByVariant = [];
            foreach ($lines as $line) {
                $variantId = $line->orderLine->variant_id;
                $quantityByVariant[$variantId] = ($quantityByVariant[$variantId] ?? 0) + $line->quantity;
            }

            $variantIds = array_keys($quantityByVariant);
            sort($variantIds, SORT_STRING);

            $levels = $this->lockLevelsAtLocation($locked->location_id, $variantIds);

            // Pas de verificare COMPLET, peste toate variantele, ÎNAINTE de orice scriere.
            foreach ($quantityByVariant as $variantId => $qty) {
                $level = $levels->get($variantId);
                $available = $level?->on_hand ?? 0;

                if ($qty > $available) {
                    throw ValidationException::withMessages([
                        'quantity' => trans('rules.shipments.insufficient_stock', ['available' => $available, 'requested' => $qty]),
                    ]);
                }
            }

            $variantsById = Variant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

            foreach ($quantityByVariant as $variantId => $qty) {
                $this->recordStockMovement->execute(
                    variant: $variantsById->get($variantId),
                    location: $locked->location,
                    delta: -$qty,
                    reason: StockMovement::REASON_SALE,
                    by: $actor,
                    refType: 'shipment',
                    refId: $locked->getKey(),
                );

                $level = $levels->get($variantId);
                $decrement = min($qty, $level->reserved);

                if ($decrement > 0) {
                    $level->decrement('reserved', $decrement);
                }
            }

            foreach ($lines as $line) {
                $line->orderLine->increment('quantity_fulfilled', $line->quantity);
            }

            $locked->status = Shipment::STATUS_IN_TRANSIT;
            $locked->shipped_at = now();
            $locked->save();

            $this->advanceOrderStatus($order);

            return $locked->fresh(['shipmentLines.orderLine', 'order', 'location']);
        });
    }

    /**
     * `partially_fulfilled` RĂMÂNE `partially_fulfilled` la un shipment parțial ulterior
     * (task brief, item 4): dacă starea țintă e identică cu cea curentă, nu se scrie nimic
     * — `OrderStatus::PartiallyFulfilled::canTransitionTo(PartiallyFulfilled)` e `false`
     * prin design (§11.3 nu listează „rămâi pe loc" ca tranziție), deci a FORȚA o scriere
     * aici ar viola `canTransitionTo()` fără niciun motiv: nu s-a produs nicio tranziție.
     */
    private function advanceOrderStatus(Order $order): void
    {
        $lines = $order->orderLines()->get(['id', 'quantity', 'quantity_fulfilled']);
        $allFulfilled = $lines->every(fn ($line) => $line->quantity_fulfilled >= $line->quantity);

        $target = $allFulfilled ? OrderStatus::Fulfilled : OrderStatus::PartiallyFulfilled;

        if ($order->status === $target) {
            return;
        }

        if (! $order->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => trans('rules.orders.cannot_transition', [
                    'target' => $target->label(),
                    'status' => $order->status->label(),
                ]),
            ]);
        }

        $order->status = $target;
        $order->save();
    }
}
