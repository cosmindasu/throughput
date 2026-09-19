<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detaliul complet al unei comenzi (`Orders/Show`, `Orders/Edit`). `can` NU e aici —
 * e propul de pagină calculat de controller (plan §1.2 regula 2), la fel ca
 * `DealResource`.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discountTotal' => (float) $this->discount_total,
            'shippingTotal' => (float) $this->shipping_total,
            'grandTotal' => (float) $this->grand_total,
            'notes' => $this->notes,
            'placedAt' => $this->placed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            // §20.5 — controllerul (`show()`/`edit()`) încarcă acest contact cu bypass
            // explicit al `NotAnonymizedContactScope`, exact ca `DealController`:
            // altfel contactul anonimizat al comenzii apare lipsă, nu doar anonim.
            'contact' => $this->whenLoaded(
                'contact',
                fn () => $this->contact ? [
                    'id' => $this->contact->id,
                    'name' => trim($this->contact->first_name.' '.$this->contact->last_name),
                    'isAnonymized' => $this->contact->isAnonymized(),
                ] : null
            ),
            'deal' => $this->whenLoaded('deal', fn () => $this->deal ? [
                'id' => $this->deal->id,
                'title' => $this->deal->title,
            ] : null),
            // FR-TEN-04 — placeholder „(deactivated)" pe owner (§6.4.1).
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => DeactivatedMemberNames::label($this->owner->name, $this->owner->id),
            ]),
            'lines' => OrderLineResource::collection($this->whenLoaded('orderLines')),
            // Faza 3, valul 2 (§11.2 pas 4, FR-ORD-03) — secțiunea Shipments + timeline.
            'shipments' => ShipmentResource::collection($this->whenLoaded('shipments')),
        ];
    }
}
