<?php

namespace App\Http\Resources\Orders;

use App\Models\ShipmentLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O linie dintr-un shipment (`Orders/Show`) — `App\Models\ShipmentLine`.
 *
 * @mixin ShipmentLine
 */
final class ShipmentLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderLineId' => $this->order_line_id,
            'description' => $this->whenLoaded('orderLine', fn () => $this->orderLine->description),
            'quantity' => $this->quantity,
        ];
    }
}
