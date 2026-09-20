<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O mișcare din registrul de stoc (specs.md §18, §10.2), append-only (ADR-004) — de aceea
 * nu există `updatedAt`: tabela n-are coloana, prin design.
 *
 * @mixin StockMovement
 */
final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variantId' => $this->variant_id,
            'locationId' => $this->location_id,
            'delta' => $this->delta,
            'reason' => $this->reason,
            'refType' => $this->ref_type,
            'refId' => $this->ref_id,
            'note' => $this->note,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
