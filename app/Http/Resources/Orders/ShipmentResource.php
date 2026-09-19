<?php

namespace App\Http\Resources\Orders;

use App\Models\Shipment;
use App\Services\Shipping\DemoShippingCarrier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detaliul unui shipment pe `Orders/Show` — `App\Models\Shipment`.
 *
 * `trackingUrl`: construit DIRECT cu `DemoShippingCarrier`, NICIODATĂ cu
 * `App\Services\Shipping\CarrierResolver::resolve()` — `resolve()` citește furnizorul
 * ACTIV al tenantului, care poate fi `shippo` (Northgate/Cascade, seed ADR-010) și ATUNCI
 * aruncă `RuntimeException` („adaptorul vine în Faza 5"). Randarea `Orders/Show` nu poate
 * depinde de asta: shipment-urile `demo` afișează link de tracking, cele `shippo`
 * (fără adaptor încă) pur și simplu nu au unul — nu o pagină 500.
 *
 * @mixin Shipment
 */
final class ShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'carrier' => $this->carrier,
            'serviceLevel' => $this->service_level,
            'trackingNumber' => $this->tracking_number,
            'labelUrl' => $this->label_url,
            'trackingUrl' => $this->tracking_number !== null && $this->carrier === 'demo'
                ? (new DemoShippingCarrier)->trackingUrl($this->resource)
                : null,
            'errorMessage' => $this->error_message,
            'shippedAt' => $this->shipped_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'lines' => ShipmentLineResource::collection($this->whenLoaded('shipmentLines')),
            'can' => [
                'retryLabel' => (bool) $request->user()?->can('retryLabel', $this->resource),
                'discard' => (bool) $request->user()?->can('discard', $this->resource),
                'markShipped' => (bool) $request->user()?->can('markShipped', $this->resource),
            ],
        ];
    }
}
