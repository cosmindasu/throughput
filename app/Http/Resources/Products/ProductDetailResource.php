<?php

namespace App\Http\Resources\Products;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Products/Show` și `Products/Edit`. Variantele apar doar pe `Show` (whenLoaded) —
 * `Edit` randează doar câmpurile proprii ale produsului (`ProductForm`).
 *
 * @mixin Product
 */
class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unitOfMeasure' => $this->unit_of_measure,
            'isActive' => (bool) $this->is_active,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
