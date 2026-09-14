<?php

namespace App\Http\Resources\Stock;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând din istoricul de mișcări al unei variante (FR-STOCK-03) —
 * `Stock/History`, paginat pe cursor prin `StockMovementList`.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delta' => (int) $this->delta,
            'reason' => $this->reason,
            'refType' => $this->ref_type,
            'refId' => $this->ref_id,
            'note' => $this->note,
            'location' => ['id' => $this->location_id, 'name' => $this->location?->name ?? '—'],
            'createdBy' => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
