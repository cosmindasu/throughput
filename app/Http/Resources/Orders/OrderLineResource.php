<?php

namespace App\Http\Resources\Orders;

use App\Models\OrderLine;
use App\Support\Orders\RemainingToShip;
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
            // Faza 3, valul 2 — „Shipped X of Y" / cantitatea maximă pe formularul „Create
            // shipment" (§11.2 pas 4): `quantity − quantity_fulfilled − shipment-urile
            // DESCHISE încă neexpediate`. Cere `orderLines.shipmentLines.shipment` încărcat
            // în controller (`RemainingToShip::forLine()` altfel ar face N+1).
            'remainingToShip' => $this->relationLoaded('shipmentLines') ? RemainingToShip::forLine($this->resource) : null,
        ];
    }
}
