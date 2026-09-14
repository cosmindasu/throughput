<?php

namespace App\Http\Controllers\Web\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\Stock\StockMovementResource;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Support\Lists\CursorPage;
use App\Support\Lists\StockMovementList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-STOCK-03 — istoric de mișcări per variantă, paginat pe cursor, filtrabil pe
 * motiv/locație/interval de date.
 */
final class StockMovementController extends Controller
{
    public function index(Request $request, Variant $variant): Response
    {
        $this->authorize('viewAny', StockMovement::class);

        $variant->load('product:id,name');

        $list = new StockMovementList($variant);
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Stock/History', [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku, 'productName' => $variant->product->name],
            'movements' => Inertia::defer(fn () => CursorPage::make(
                $listQuery->paginate($list->query($listQuery, $user)),
                StockMovementResource::class,
            )),
            'list' => $listQuery->toArray(),
            'reasons' => StockMovement::REASONS,
        ]);
    }
}
