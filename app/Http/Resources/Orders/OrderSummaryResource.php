<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Un rând din `Orders/Index` — `App\Models\Order`, forma redusă (fără linii).
 *
 * `can` per RÂND, nu doar per pagină — un Agent pe „All orders" vede tot tenantul,
 * dar editează/anulează doar ce deține (§7.5), la fel ca `DealSummaryResource`.
 *
 * @mixin Order
 */
final class OrderSummaryResource extends JsonResource
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
            'grandTotal' => (float) $this->grand_total,
            'placedAt' => $this->placed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            // FR-TEN-04 — placeholder „(deactivated)" pe owner (§6.4.1).
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => DeactivatedMemberNames::label($this->owner->name, $this->owner->id),
            ]),
            'can' => [
                'edit' => Gate::allows('update', $this->resource),
                'cancel' => Gate::allows('cancel', $this->resource),
            ],
        ];
    }
}
