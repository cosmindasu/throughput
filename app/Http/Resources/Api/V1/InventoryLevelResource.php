<?php

namespace App\Http\Resources\Api\V1;

use App\Models\InventoryLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nivelul de stoc al unei variante într-o locație (specs.md §18, §10.5).
 *
 * `available` NU se recalculează aici: vine din `InventoryLevel::available()`, singurul
 * loc care știe ce înseamnă „disponibil de vânzare" (BR-STOCK-04). Un API care și-ar
 * scrie propria scădere ar fi a doua definiție a aceluiași număr.
 *
 * E și singura listă din API care expune perechea `variantId` + `locationId`, deci ruta
 * prin care un consumator află ID-urile de care are nevoie ca să posteze o mișcare de
 * stoc sau o linie de comandă.
 *
 * @mixin InventoryLevel
 */
final class InventoryLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'variantId' => $this->variant_id,
            'sku' => $this->whenLoaded('variant', fn () => $this->variant?->sku),
            'locationId' => $this->location_id,
            'locationName' => $this->whenLoaded('location', fn () => $this->location?->name),
            'onHand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
