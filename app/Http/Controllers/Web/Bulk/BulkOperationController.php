<?php

namespace App\Http\Controllers\Web\Bulk;

use App\Actions\Bulk\DispatchBulkOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\CancelDraftOrdersRequest;
use App\Http\Requests\Bulk\ReassignOwnerRequest;
use App\Http\Requests\Bulk\SetProductActiveRequest;
use App\Http\Requests\Bulk\UpdateProductPriceRequest;
use App\Http\Resources\Bulk\BulkOperationResource;
use App\Models\BulkOperation;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mecanismul generic de operații în masă de SCRIERE (§13.2) — dispecerizare, status și
 * anulare, comune oricărui `resource_type`/`action`. `reassignOwner()` e generic pe
 * `{resourceType}` (Accounts, Deals, Orders — §13.5); `cancelDraftOrders()`,
 * `updateProductPrice()` și `setProductActive()` sunt specifice unei singure resurse, deci
 * au fiecare propria rută, fără segmentul `{resourceType}`.
 *
 * Distinct de `App\Http\Controllers\Web\Exports\ExportController` (operații de CITIRE,
 * Faza 2): modelul `BulkOperation` e comun, dar prezentarea diferă (progres + „Cancel", nu
 * „Download").
 */
final class BulkOperationController extends Controller
{
    public function reassignOwner(
        ReassignOwnerRequest $request,
        string $resourceType,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve($resourceType);

        $this->authorize('bulkReassignOwner', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::REASSIGN_OWNER,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: ['owner_user_id' => $request->validated('owner_user_id')],
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.reassign_owner_started'));
    }

    /**
     * §13.5, §11.3 — anulare în masă a comenzilor `draft`. Fără payload de acțiune: statul
     * țintă (`cancelled`) și precondiția (`status = draft`) sunt fixe în
     * `App\Actions\Bulk\CancelDraftOrdersAction`, nu vin din cerere.
     */
    public function cancelDraftOrders(
        CancelDraftOrdersRequest $request,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve('orders');

        $this->authorize('bulkCancel', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::CANCEL_DRAFT_ORDERS,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: [],
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.cancel_draft_orders_started'));
    }

    /** §13.5 — preț în masă pe variantele produselor selectate (procent/sumă, +/-). */
    public function updateProductPrice(
        UpdateProductPriceRequest $request,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve('products');

        $this->authorize('bulkWrite', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::UPDATE_PRICE,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: $request->pricePayload(),
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.update_price_started'));
    }

    /** §13.5 — activare/dezactivare în masă a produselor selectate. */
    public function setProductActive(
        SetProductActiveRequest $request,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve('products');

        $this->authorize('bulkWrite', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::SET_ACTIVE,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: ['active' => $request->boolean('active')],
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.set_active_started'));
    }

    public function show(BulkOperation $operation): Response
    {
        $this->authorize('view', $operation);

        // P3 (code review) — `Bulk/Show` e pagina de progres/anulare a operațiilor de
        // SCRIERE (`Bus::batch()`); un export (`action === 'export'`) deschis prin
        // această rută ar arăta un buton „Cancel" fără niciun efect real (exportul
        // rulează ca un job unic, fără batch — vezi `BulkOperationPolicy::cancel()`).
        // Exporturile rămân pe ruta lor, `exports.show` (`ExportController::show()`).
        abort_unless(BulkChunkActions::isRegistered($operation->action), 404);

        return Inertia::render('Bulk/Show', [
            'operation' => new BulkOperationResource($operation),
        ]);
    }

    /**
     * P1-001 (code review) — „Cancel" trebuia să facă ceva ȘI cât timp planificatorul n-a
     * scris încă un `batch_id` (`status = pending`, sau `running` în fereastra scurtă
     * dintre scrierea „running" și `Bus::batch()->dispatch()` din
     * `PlanBulkOperationJob::plan()`). Un singur `UPDATE` atomic, condiționat ȘI pe
     * `batch_id IS NULL`, ȘI pe stare — nu un citește-apoi-scrie separat, care ar putea
     * călca o tranziție concurentă a planificatorului. Planificatorul, la rândul lui,
     * reverifică starea chiar înainte de `Bus::batch()->dispatch()` (fără nicio scriere
     * de bază de date între verificare și dispatch): o anulare care a apucat să se
     * COMITĂ înaintea acelei verificări nu mai lasă planificatorul să creeze batch-ul.
     */
    public function cancel(BulkOperation $operation): RedirectResponse
    {
        $this->authorize('cancel', $operation);

        // Nu instanța legată de rută (rezolvată la începutul cererii — poate fi stale
        // față de planificator, care rulează concurent pe alt proces/worker).
        $fresh = $operation->fresh();

        if ($fresh === null) {
            return back()->with('success', __('flash.bulk.operation_missing'));
        }

        if ($fresh->batch_id !== null) {
            Bus::findBatch($fresh->batch_id)?->cancel();

            return back()->with('success', __('flash.bulk.cancelling_in_progress'));
        }

        $cancelled = BulkOperation::query()
            ->whereKey($fresh->getKey())
            ->whereNull('batch_id')
            ->whereIn('status', [BulkOperation::STATUS_PENDING, BulkOperation::STATUS_RUNNING])
            ->update(['status' => BulkOperation::STATUS_CANCELLED]);

        if ($cancelled > 0) {
            return back()->with('success', __('flash.bulk.cancelled'));
        }

        // Am pierdut cursa: planificatorul a scris `batch_id` chiar între citirea de mai
        // sus și `UPDATE`-ul atomic. Recitim și anulăm batch-ul proaspăt creat, ca la
        // cazul obișnuit — sau, dacă operația s-a terminat deja pe cont propriu între
        // timp, spunem exact atât.
        $fresh = $fresh->fresh();

        if ($fresh?->batch_id !== null) {
            Bus::findBatch($fresh->batch_id)?->cancel();

            return back()->with('success', __('flash.bulk.cancelling_in_progress'));
        }

        return back()->with('success', __('flash.bulk.already_finished'));
    }
}
