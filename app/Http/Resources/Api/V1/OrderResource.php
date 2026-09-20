<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul public al unei comenzi (specs.md §18, §11).
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
            'accountId' => $this->account_id,
            'contactId' => $this->contact_id,
            'dealId' => $this->deal_id,
            'ownerUserId' => $this->owner_user_id,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discountTotal' => (float) $this->discount_total,
            'shippingTotal' => (float) $this->shipping_total,
            'grandTotal' => (float) $this->grand_total,
            'notes' => $this->notes,
            'placedAt' => $this->placed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'lines' => OrderLineResource::collection($this->whenLoaded('orderLines')),
        ];
    }
}
