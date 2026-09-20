<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Stock\RecordStockMovementAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Location;
use App\Models\Scopes\TenantScope;
use App\Models\StockMovement;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /api/v1/stock-movements` — scopuri `inventory:read` / `inventory:write`,
 * `Idempotency-Key` obligatoriu pe POST (§18.4, care îl numește „ajustări").
 *
 * Motivele acceptate prin API sunt exact cele INTRODUSE DE OM (`receipt`, `adjustment`).
 * `sale`, `transfer` și `return` sunt scrise de fluxurile interne (confirmarea unei
 * comenzi, `TransferStockAction`) și au invariante proprii — un `sale` postat din afară
 * ar fi o ieșire de stoc fără comandă, adică exact genul de rând care face `stock:reconcile`
 * să diveargă.
 *
 * Registrul e append-only (ADR-004): nu există `PUT`/`DELETE` aici, iar `AppendOnly` le
 * blochează oricum la nivel de model. O corecție e o mișcare NOUĂ (BR-STOCK-01).
 */
final class StockMovementController extends ApiController
{
    /** @var list<string> */
    private const API_REASONS = [StockMovement::REASON_RECEIPT, StockMovement::REASON_ADJUSTMENT];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $movements = StockMovement::query()
            ->when($request->filled('variantId'), fn ($query) => $query->where('variant_id', $request->string('variantId')))
            ->when($request->filled('locationId'), fn ($query) => $query->where('location_id', $request->string('locationId')))
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->string('reason')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $movements, StockMovementResource::class);
    }

    public function store(Request $request, RecordStockMovementAction $action): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $tenantId = TenantScope::requireCurrentTenantId();

        $validated = $request->validate([
            'variant_id' => [
                'required', 'string',
                Rule::exists('variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'location_id' => [
                'required', 'string',
                Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', Rule::in(self::API_REASONS)],
            // BR-STOCK-01 — nota e obligatorie pe ajustare. Aceeași regulă ca în
            // `AdjustStockRequest`, iar `RecordStockMovementAction` o mai verifică o dată
            // (a doua treaptă): acțiunea rămâne corectă chemată din orice canal.
            'note' => ['required_if:reason,'.StockMovement::REASON_ADJUSTMENT, 'nullable', 'string', 'max:500'],
        ], [
            'delta.not_in' => 'The movement must change the quantity by at least 1.',
            'note.required_if' => 'Explain why you are correcting this quantity.',
        ]);

        $variant = $this->findForTenant(Variant::query(), $validated['variant_id']);
        $location = $this->findForTenant(Location::query(), $validated['location_id']);

        $movement = $action->execute(
            variant: $variant,
            location: $location,
            delta: (int) $validated['delta'],
            reason: $validated['reason'],
            by: $request->user(),
            note: $validated['note'] ?? null,
        );

        return $this->item($request, new StockMovementResource($movement), Response::HTTP_CREATED);
    }
}
