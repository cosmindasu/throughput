<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Deals\CreateDealAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Deals\StoreDealRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /api/v1/deals` — scopuri `deals:read` / `deals:write` (FR-API-01).
 *
 * Crearea trece prin `CreateDealAction`, exact ca `DealController` de pe web: pipeline-ul
 * implicit, prima etapă și primul `deal_stage_events` sunt reguli de domeniu (§9.2), nu
 * detalii de canal.
 */
final class DealController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deal::class);

        $deals = Deal::query()
            ->with('stage:id,name')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('accountId'), fn ($query) => $query->where('account_id', $request->string('accountId')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $deals, DealResource::class);
    }

    public function show(Request $request, string $deal): JsonResponse
    {
        $model = $this->findForTenant(Deal::query()->with('stage:id,name'), $deal);

        $this->authorize('view', $model);

        return $this->item($request, new DealResource($model));
    }

    public function store(StoreDealRequest $request, CreateDealAction $action): JsonResponse
    {
        $this->authorize('create', Deal::class);

        $deal = $action->execute($request->validated(), $request->user());

        return $this->item($request, new DealResource($deal->load('stage:id,name')), Response::HTTP_CREATED);
    }
}
