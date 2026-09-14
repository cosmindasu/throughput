<?php

namespace App\Http\Resources\Orders;

use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O linie de comandă (`Orders/Show`, `Orders/Edit`) — `App\Models\OrderLine`.
 *
 * @mixin OrderLine
 */
final class OrderLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variantId' => $this->variant_id,
            'sku' => $this->whenLoaded('variant', fn () => $this->variant->sku),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unitPrice' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'lineTotal' => (float) $this->line_total,
            'quantityFulfilled' => $this->quantity_fulfilled,
        ];
    }
}
