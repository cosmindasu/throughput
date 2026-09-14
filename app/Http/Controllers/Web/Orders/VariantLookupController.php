<?php

namespace App\Http\Controllers\Web\Orders;

use App\Http\Controllers\Controller;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Order;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint JSON pentru selectorul de variantă din `Orders/Create`/`Orders/Edit` — la
 * fel ca `AccountLookupController`, dar pentru linii de comandă. US-STOCK-02:
 * `available = on_hand - reserved` la locația implicită, calculat aici, ca formularul
 * să nu ghicească disponibilul din alt loc.
 *
 * Autorizare pe `orders.view`: un utilizator care poate construi/vedea o comandă are
 * nevoie să caute variante, indiferent dacă are `products.view` separat (Agent are
 * ambele oricum, dar cuplarea corectă e cu comanda, nu cu catalogul).
 */
final class VariantLookupController extends Controller
{
    private const MAX_RESULTS = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $raw = $request->query('q');
        $q = is_string($raw) ? mb_substr(trim($raw), 0, 100) : '';

        $location = Location::query()->where('is_default', true)->first()
            ?? Location::query()->oldest('created_at')->first();

        $variants = Variant::query()
            ->select(['id', 'sku', 'product_id', 'price'])
            ->with('product:id,name')
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('sku', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereHas('product', fn ($product) => $product->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))))
            ->orderBy('sku')
            ->limit(self::MAX_RESULTS)
            ->get();

        $levels = $location !== null
            ? InventoryLevel::query()
                ->where('location_id', $location->getKey())
                ->whereIn('variant_id', $variants->pluck('id'))
                ->get()
                ->keyBy('variant_id')
            : collect();

        return response()->json([
            'data' => $variants->map(function (Variant $variant) use ($levels): array {
                $level = $levels->get($variant->id);
                $available = $level !== null ? $level->on_hand - $level->reserved : 0;

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product?->name ?? $variant->sku,
                    'price' => (float) $variant->price,
                    'available' => $available,
                ];
            })->all(),
        ]);
    }
}
