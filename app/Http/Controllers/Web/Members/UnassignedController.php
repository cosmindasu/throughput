<?php

namespace App\Http\Controllers\Web\Members;

use App\Actions\Bulk\DispatchBulkOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\ReassignUnassignedRequest;
use App\Http\Resources\DealSummaryResource;
use App\Http\Resources\Orders\OrderSummaryResource;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Order;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Lists\CursorPage;
use App\Support\Lists\DealList;
use App\Support\Lists\OrderList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-TEN-05 — vederea „Unassigned" (§6.4.1, ADR-011): deals deschise + comenzi active ai
 * membrilor DEZACTIVAȚI, grupate pe tip. NU conturi — decizie deja luată (vezi raportul
 * pachetului): Gherkin-ul US-TEN-03 numără explicit „12 open deals and 3 active orders",
 * niciodată conturi, iar „cele 47 de conturi rămân atribuite lui Jane" e literal.
 *
 * Owner/Manager (`unassigned.view`, §7.4). Reatribuirea în masă de aici e o singură
 * acțiune pe TOT setul filtrat („owner=unassigned" + statusul potrivit), nu o selecție rând
 * cu rând (`BulkSelectionBar.tsx` rămâne neatinsă — vezi raportul pachetului): vederea
 * ÎNSĂȘI e deja „setul care are nevoie de un owner nou", deci „Select all" e implicit.
 */
final class UnassignedController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('unassigned.view'), 403);

        $user = $request->user();

        $dealList = new DealList;
        $dealsQuery = $dealList->fromState(['filter' => ['owner' => 'unassigned', 'status' => Deal::STATUS_OPEN]]);

        $orderList = new OrderList;
        $ordersQuery = $orderList->fromState(['filter' => ['owner' => 'unassigned', 'status' => OrderList::STATUS_ACTIVE]]);

        return Inertia::render('Unassigned/Index', [
            'deals' => Inertia::defer(fn () => CursorPage::make(
                $dealsQuery->paginate($dealList->query($dealsQuery, $user)),
                DealSummaryResource::class,
            )),
            'orders' => Inertia::defer(fn () => CursorPage::make(
                $ordersQuery->paginate($orderList->query($ordersQuery, $user)),
                OrderSummaryResource::class,
            )),
            'can' => [
                'reassign' => $user->can('bulkReassignOwner', Deal::class) && $user->can('bulkReassignOwner', Order::class),
            ],
            // „Reassign to…" — membrii activi, ca `MembersController::index()`. Tabelul
            // e mic (câțiva membri per tenant), deci o interogare simplă, needeferred.
            'activeMembers' => Membership::query()
                ->where('status', Membership::STATUS_ACTIVE)
                ->with('user:id,name')
                ->get()
                ->map(fn (Membership $membership) => ['id' => $membership->user_id, 'name' => $membership->user?->name])
                ->values(),
        ]);
    }

    /**
     * „Reassign to…" pe ÎNTREAGA vedere (ambele tipuri), cu un `group_id` comun — același
     * mecanism ca la punctul 3 (`MembersController::deactivate()`), aplicat pe filtrul
     * `owner=unassigned` în loc de un `owner_user_id` anume: cine ajunge aici s-a putut
     * acumula de la MAI MULȚI membri dezactivați, nu de la unul singur.
     */
    public function reassign(ReassignUnassignedRequest $request): RedirectResponse
    {
        $this->authorize('bulkReassignOwner', Deal::class);
        $this->authorize('bulkReassignOwner', Order::class);

        $newOwnerId = (string) $request->string('new_owner_user_id');
        $groupId = (string) Str::ulid();
        $dispatch = app(DispatchBulkOperationAction::class);

        $this->dispatchReassignment($dispatch, $request, 'deals', ['owner' => 'unassigned', 'status' => Deal::STATUS_OPEN], $newOwnerId, $groupId);
        $this->dispatchReassignment($dispatch, $request, 'orders', ['owner' => 'unassigned', 'status' => OrderList::STATUS_ACTIVE], $newOwnerId, $groupId);

        return redirect()->route('bulk.groups.show', $groupId)->with('success', 'Reassigning every unassigned record — this page updates automatically.');
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function dispatchReassignment(
        DispatchBulkOperationAction $dispatch,
        ReassignUnassignedRequest $request,
        string $resourceType,
        array $filters,
        string $newOwnerId,
        string $groupId,
    ): void {
        $resource = BulkWritableResources::resolve($resourceType);
        $list = app($resource->listClass());
        $listQuery = $list->fromState(['filter' => $filters]);

        $dispatch->execute(
            user: $request->user(),
            resource: $resource,
            action: BulkChunkActions::REASSIGN_OWNER,
            listQuery: $listQuery,
            ids: null,
            actionPayload: ['owner_user_id' => $newOwnerId],
            groupId: $groupId,
            confirmed: true,
        );
    }
}
