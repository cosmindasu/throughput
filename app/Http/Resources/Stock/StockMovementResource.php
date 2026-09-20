<?php

namespace App\Http\Resources\Stock;

use App\Models\StockMovement;
use App\Support\Members\DeactivatedMemberNames;
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
            // FR-TEN-04 — istoricul de stoc e prin definiție retrospectiv: autorul unei
            // ajustări de acum trei luni poate fi între timp dezactivat, iar rândul trebuie
            // să-l arate ca „Nume (deactivated)", nu cu numele nemarcat (plan §11 — la
            // nivelul `Resource`-ului, nu în componenta React).
            'createdBy' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => DeactivatedMemberNames::label($this->createdBy->name, $this->createdBy->id),
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
