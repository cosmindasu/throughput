<?php

namespace App\Http\Resources\Products;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rândul din `Products/Index` (§10, FR-CRM-03 analog pentru catalog). Fără `canEdit` per
 * rând — spre deosebire de `AccountResource`, produsele n-au ownership (§7.4: `R` fix
 * pentru Agent/Viewer, `CRUD` fix pentru Owner/Manager), deci un `can` de pagină ajunge.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, category: string|null, unitOfMeasure: string, isActive: bool, variantsCount: int, lowStockVariantsCount: int, createdAt: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unitOfMeasure' => $this->unit_of_measure,
            'isActive' => (bool) $this->is_active,
            'variantsCount' => (int) $this->variants_count,
            // FR-STOCK-02 — subinterogare agregată din `ProductList::baseQuery()`, nu un
            // `withCount`/o buclă PHP peste variante (App\Support\Stock\LowStockRule).
            'lowStockVariantsCount' => (int) $this->low_stock_variants_count,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
