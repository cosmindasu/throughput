<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O linie de comandă, în contractul public (specs.md §18).
 *
 * `cost` NU apare aici, și nu e o omisiune: marja e ascunsă pentru Agent și Viewer chiar
 * și în interfață (§7.4, `Permissions::canViewCost()`). Un jeton emis de un Agent ar fi
 * putut, altfel, să citească exact cifra pe care ecranul i-o ascunde — API-ul nu poate fi
 * o portiță în jurul matricei de permisiuni.
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
            'sku' => $this->whenLoaded('variant', fn () => $this->variant?->sku),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'quantityFulfilled' => $this->quantity_fulfilled,
            'unitPrice' => (float) $this->unit_price,
            'discount' => (float) $this->discount,
            'lineTotal' => (float) $this->line_total,
        ];
    }
}
