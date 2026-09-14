<?php

namespace App\Http\Resources\Products;

use App\Models\InventoryLevel;
use App\Models\Variant;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * §7.4 — `cost` (marja) e ascunsă pentru Agent și Viewer, la nivelul RESURSEI, nu doar
 * al interfeței (task brief, Pachetul A punctul 1): `$this->when()` OMITE cheia din JSON
 * pentru rolurile fără drept, nu doar o randează `null` — un `null` tot ar confirma
 * existența câmpului, plus ar cere clientului să distingă „ascuns" de „fără cost".
 *
 * `onHand`/`reserved`/`available` apar DOAR când `inventoryLevels` a fost încărcată
 * explicit (`whenLoaded`) — un rând din `Products/Show` le vrea, un rând dintr-un
 * dropdown de selecție de variantă (alt pachet) nu are motiv să plătească suma.
 *
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $levels = $this->whenLoaded('inventoryLevels');

        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'sku' => $this->sku,
            'attributes' => $this->attributes ?? [],
            'price' => (float) $this->price,
            'cost' => $this->when(
                $user !== null && Permissions::canViewCost($user),
                fn () => (float) $this->cost,
            ),
            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'isActive' => (bool) $this->is_active,
            'onHand' => $this->when($levels instanceof Collection, fn () => $levels->sum('on_hand')),
            'reserved' => $this->when($levels instanceof Collection, fn () => $levels->sum('reserved')),
            'available' => $this->when(
                $levels instanceof Collection,
                fn () => $levels->sum(fn (InventoryLevel $level) => $level->available()),
            ),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
