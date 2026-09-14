<?php

namespace App\Http\Controllers\Web\Bulk;

use App\Actions\Bulk\DispatchBulkOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\ReassignOwnerRequest;
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
 * anulare, comune oricărui `resource_type`/`action`. `reassignOwner()` e singura acțiune a
 * acestui val (Accounts + Deals, §13.5); o resursă nouă își adaugă doar o intrare în
 * `BulkWritableResources`, nu o rută nouă.
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
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', 'Bulk operation started — this page updates automatically.');
    }

    public function show(BulkOperation $operation): Response
    {
        $this->authorize('view', $operation);

        return Inertia::render('Bulk/Show', [
            'operation' => new BulkOperationResource($operation),
        ]);
    }

    public function cancel(BulkOperation $operation): RedirectResponse
    {
        $this->authorize('cancel', $operation);

        if ($operation->batch_id !== null) {
            Bus::findBatch($operation->batch_id)?->cancel();
        }

        return back()->with('success', 'Cancelling — rows already in progress will finish, the rest stop.');
    }
}
