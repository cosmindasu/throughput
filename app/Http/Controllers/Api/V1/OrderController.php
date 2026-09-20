<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /api/v1/orders` — scopuri `orders:read` / `orders:write` (FR-API-01),
 * `Idempotency-Key` obligatoriu pe POST (FR-API-03, §18.4).
 *
 * **Cele două straturi de autorizare sunt independente și se compun.** Jetonul spune ce
 * poate face INTEGRAREA (scopuri), rolul spune ce poate face PERSOANA care l-a emis
 * (§7.4). Un jeton `orders:write` emis de un Viewer primește `403` de la `OrderPolicy`,
 * nu `201` — altfel API-ul ar fi o cale de a emite jetoane mai puternice decât cel care
 * le emite, adică exact escaladarea pe care matricea de permisiuni o exclude.
 */
final class OrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $orders = Order::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('accountId'), fn ($query) => $query->where('account_id', $request->string('accountId')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $orders, OrderResource::class);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $model = $this->findForTenant(Order::query()->with('orderLines.variant:id,sku'), $order);

        $this->authorize('view', $model);

        return $this->item($request, new OrderResource($model));
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action): JsonResponse
    {
        $this->authorize('create', Order::class);

        $order = $action->execute($request->validated(), $request->user());

        return $this->item(
            $request,
            new OrderResource($order->load('orderLines.variant:id,sku')),
            Response::HTTP_CREATED,
        );
    }
}
