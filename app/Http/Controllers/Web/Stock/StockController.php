<?php

namespace App\Http\Controllers\Web\Stock;

use App\Actions\Stock\RecordStockMovementAction;
use App\Actions\Stock\TransferStockAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\AdjustStockRequest;
use App\Http\Requests\Stock\ReceiveStockRequest;
use App\Http\Requests\Stock\TransferStockRequest;
use App\Http\Resources\Products\VariantResource;
use App\Http\Resources\Stock\StockLevelResource;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nivelurile de stoc ale unei variante — specs.md §10, Pachetul A punctele 2/3/6.
 * `RecordStockMovementAction`/`TransferStockAction` fac scrierea (BR-STOCK-02/03);
 * controllerul rămâne validare + autorizare + forma de răspuns.
 */
final class StockController extends Controller
{
    public function show(Request $request, Variant $variant): Response
    {
        $this->authorize('viewAny', StockMovement::class);

        $variant->load(['product:id,name', 'inventoryLevels.location']);
        $user = $request->user();

        return Inertia::render('Stock/Show', [
            'variant' => new VariantResource($variant),
            'levels' => StockLevelResource::collection($variant->inventoryLevels),
            'locations' => $this->locationOptions(),
            'can' => [
                'adjust' => $user->can('create', StockMovement::class),
            ],
        ]);
    }

    public function receive(ReceiveStockRequest $request, Variant $variant, RecordStockMovementAction $action): RedirectResponse
    {
        $location = Location::query()->findOrFail($request->validated('location_id'));

        $action->execute(
            variant: $variant,
            location: $location,
            delta: (int) $request->validated('quantity'),
            reason: StockMovement::REASON_RECEIPT,
            by: $request->user(),
            note: $request->validated('note'),
        );

        return back()->with('success', 'Stock received.');
    }

    public function adjust(AdjustStockRequest $request, Variant $variant, RecordStockMovementAction $action): RedirectResponse
    {
        $location = Location::query()->findOrFail($request->validated('location_id'));

        $action->execute(
            variant: $variant,
            location: $location,
            delta: (int) $request->validated('delta'),
            reason: StockMovement::REASON_ADJUSTMENT,
            by: $request->user(),
            note: $request->validated('note'),
        );

        return back()->with('success', 'Stock adjusted.');
    }

    public function transfer(TransferStockRequest $request, Variant $variant, TransferStockAction $action): RedirectResponse
    {
        $from = Location::query()->findOrFail($request->validated('from_location_id'));
        $to = Location::query()->findOrFail($request->validated('to_location_id'));

        $action->execute(
            variant: $variant,
            from: $from,
            to: $to,
            quantity: (int) $request->validated('quantity'),
            by: $request->user(),
            note: $request->validated('note'),
        );

        return back()->with('success', 'Stock transferred.');
    }

    /**
     * Toate locațiile tenantului — dropdown de recepție/ajustare/transfer. Array simplu,
     * nu Resource (la fel ca `AccountController::ownerOptions()`): CRUD-ul de locații
     * propriu-zis rămâne în afara acestui lot (task brief, „în afara lotului").
     *
     * @return list<array{id: string, name: string}>
     */
    private function locationOptions(): array
    {
        return Location::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->values()
            ->all();
    }
}
