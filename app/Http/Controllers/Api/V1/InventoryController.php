<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\InventoryLevelResource;
use App\Models\InventoryLevel;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/inventory` — scop `inventory:read` (FR-API-01).
 *
 * Proiecția `inventory_levels` (§10.5), nu registrul: un consumator care întreabă „cât am
 * pe stoc" nu trebuie să însumeze el mișcările. Registrul rămâne la
 * `GET /api/v1/stock-movements`, pentru cine vrea istoricul.
 *
 * Autorizarea se face pe `StockMovementPolicy::viewAny` (`stock.view`) — aceeași
 * permisiune care deschide ecranul de stoc: nivelurile și registrul sunt același rând din
 * matricea §7.4, deci nu-și merită o a doua politică.
 */
final class InventoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $levels = InventoryLevel::query()
            ->with(['variant:id,sku', 'location:id,name'])
            ->when($request->filled('variantId'), fn ($query) => $query->where('variant_id', $request->string('variantId')))
            ->when($request->filled('locationId'), fn ($query) => $query->where('location_id', $request->string('locationId')))
            // `inventory_levels` n-are `created_at` (proiecție materializată), deci
            // ordonarea stabilă se face pe cheie, nu pe timp.
            ->orderBy('variant_id')
            ->orderBy('location_id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $levels, InventoryLevelResource::class);
    }
}
