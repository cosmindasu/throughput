<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /api/v1/invoices` — scopuri `invoices:read` / `invoices:write`,
 * `Idempotency-Key` obligatoriu pe POST (§18.4).
 *
 * `invoices:write` face parte din cele 11 scopuri ale FR-API-01 (specs.md, corectate în
 * v1.23 — versiunile anterioare enumerau doar opt și lăsau acest endpoint inaccesibil
 * oricărui jeton, deși §18.4 îl cere explicit). Sursa unică a catalogului rămâne
 * `App\Models\ApiToken::abilityCatalog()`, de unde se hrănesc și `EnsureTokenAbility`,
 * și `openapi/throughput-v1.yaml`.
 *
 * `CreateInvoiceAction` rămâne singurul loc care alocă `invoice_number` (BR-ORD-02-alike)
 * și singurul care decide din ce stări de comandă se poate factura — API-ul nu repetă
 * niciuna dintre reguli, doar le expune.
 */
final class InvoiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('orderId'), fn ($query) => $query->where('order_id', $request->string('orderId')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $invoices, InvoiceResource::class);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        $model = $this->findForTenant(Invoice::query(), $invoice);

        $this->authorize('view', $model);

        return $this->item($request, new InvoiceResource($model));
    }

    public function store(Request $request, CreateInvoiceAction $action): JsonResponse
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        // `Rule::exists()` emite SQL brut, care ocolește global scope-ul Eloquent — clauza
        // `tenant_id` explicită e obligatorie (același comentariu ca în `StoreDealRequest`).
        // Nu e o a doua plasă de securitate, ci un 422 inteligibil în locul unui 404 al
        // `findForTenant()` de mai jos.
        $validated = $request->validate([
            'order_id' => [
                'required', 'string',
                Rule::exists('orders', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
        ]);

        $order = $this->findForTenant(Order::query(), $validated['order_id']);

        $this->authorize('create', [Invoice::class, $order]);

        $invoice = $action->execute($order);

        return $this->item($request, new InvoiceResource($invoice), Response::HTTP_CREATED);
    }
}
