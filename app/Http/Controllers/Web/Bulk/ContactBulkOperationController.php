<?php

namespace App\Http\Controllers\Web\Bulk;

use App\Actions\Bulk\DispatchBulkOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bulk\DeleteContactsRequest;
use App\Http\Requests\Bulk\OptOutContactsRequest;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use Illuminate\Http\RedirectResponse;

/**
 * GDPR-04, §13.5 — operații în masă de SCRIERE specifice Contactelor: opt-out (Art. 21) și
 * ștergere/anonimizare RTBF (Art. 17). Controller NOU, distinct de
 * `App\Http\Controllers\Web\Bulk\BulkOperationController`: acela nu era în felia de fișiere
 * a acestui lot (alte sesiuni lucrează pe el în paralel, în ACELAȘI working tree), deci
 * fiecare metodă de aici reutilizează DOAR mecanismul comun de planificare
 * (`App\Actions\Bulk\DispatchBulkOperationAction`), exact cum fac `reassignOwner()`/
 * `cancelDraftOrders()`/`setProductActive()` din celălalt controller — fără să le
 * duplice, dincolo de firul subțire de „rezolvă resursa → autorizează → parsează filtrul →
 * dispecerizează → redirecționează", inevitabil identic la orice acțiune bulk nouă (vezi
 * raportul lotului pentru semnalarea explicită a acestei suprapuneri minore).
 */
final class ContactBulkOperationController extends Controller
{
    /** GDPR-04, Art. 21 — opt-out în masă pe contactele selectate. */
    public function optOut(
        OptOutContactsRequest $request,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve('contacts');

        $this->authorize('bulkOptOut', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::CONTACTS_MARK_OPTED_OUT,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: [],
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        // Textul generic („Bulk operation started — this page updates automatically."), deși
        // cheia poartă numele primei acțiuni care l-a folosit: `lang/**` nu e al acestui lot,
        // iar o cheie proprie inexistentă s-ar fi afișat brut. Același text mai jos.
        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.reassign_owner_started'));
    }

    /** GDPR-04, Art. 17 (RTBF) — ștergere/anonimizare în masă pe contactele selectate. */
    public function delete(
        DeleteContactsRequest $request,
        DispatchBulkOperationAction $dispatch,
    ): RedirectResponse {
        $resource = BulkWritableResources::resolve('contacts');

        $this->authorize('bulkDelete', $resource->modelClass());

        $list = app($resource->listClass());
        $listQuery = $list->parse($request);

        $operation = $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::CONTACTS_DELETE,
            listQuery: $listQuery,
            ids: $request->idsOrNull(),
            actionPayload: [],
            confirmed: $request->confirmed(),
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return redirect()
            ->route('bulk.show', $operation)
            ->with('success', __('flash.bulk.reassign_owner_started'));
    }
}
