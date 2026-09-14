<?php

namespace App\Http\Resources\Stock;

use App\Models\InventoryLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând din `Stock/Show` — nivelul unei variante la o singură locație. `available`
 * folosește `InventoryLevel::available()` (§10.5), aceeași metodă pe care o citește și
 * `VariantResource` la agregare.
 *
 * @mixin InventoryLevel
 */
class StockLevelResource extends JsonResource
{
    /**
     * @return array{id: string, locationId: string, locationName: string, onHand: int, reserved: int, available: int, updatedAt: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'locationId' => $this->location_id,
            'locationName' => $this->location?->name ?? '—',
            'onHand' => (int) $this->on_hand,
            'reserved' => (int) $this->reserved,
            'available' => $this->available(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
