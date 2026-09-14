<?php

namespace App\Http\Controllers\Web\Orders;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderLinesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Resources\Orders\OrderContactOptionResource;
use App\Http\Resources\Orders\OrderOwnerOptionResource;
use App\Http\Resources\Orders\OrderResource;
use App\Http\Resources\Orders\OrderSummaryResource;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Scopes\NotAnonymizedContactScope;
use App\Support\Lists\CursorPage;
use App\Support\Lists\OrderList;
use App\Support\RecentlyViewed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de comenzi — plan §9 task 1, specs.md §11.2/§11.4. Controller subțire: creare/
 * editare de linii în `App\Actions\Orders`, tranzițiile de stare în controllere
 * dedicate (`ConfirmOrderController`, `CancelOrderController`), la fel cum
 * `DealStageController` e separat de `DealController`.
 */
final class OrderController extends Controller
{
    public function index(Request $request, OrderList $list): Response
    {
        Gate::authorize('viewAny', Order::class);

        $user = $request->user();
        $query = $list->parse($request);
        $paginator = $query->paginate($list->query($query, $user));

        return Inertia::render('Orders/Index', [
            'orders' => Inertia::defer(fn () => CursorPage::make(
                $paginator,
                OrderSummaryResource::class,
            )),
            'filters' => $query->toArray(),
            'can' => [
                'create' => Gate::allows('create', Order::class),
            ],
        ]);
    }

    /**
     * US-ORD-01 — precompletat din `?account=` (link „New order" din pagina unui
     * cont), simetric cu `DealController::create()`. Fără parametru, formularul
     * pornește cu contul gol, ales prin `AccountCombobox`.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Order::class);

        $raw = $request->query('account');
        $accountId = is_string($raw) ? $raw : null;
        $account = $accountId !== null && $accountId !== '' ? Account::query()->findOrFail($accountId) : null;

        return Inertia::render('Orders/Create', [
            'account' => $account !== null ? ['id' => $account->id, 'name' => $account->name] : null,
            'contacts' => $this->contactsForAccount($account),
            'can' => ['changeOwner' => Gate::allows('changeOwner', Order::class)],
            'owners' => Gate::allows('changeOwner', Order::class) ? $this->ownerOptions() : [],
        ]);
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action): RedirectResponse
    {
        Gate::authorize('create', Order::class);

        $data = $request->validated();

        // Code review P2-002 — `Gate::allows('changeOwner', …)`, NU
        // `Permissions::restrictedToOwnRecords()` direct: catalogul (`orders.change_owner`)
        // e acum sursa unică, ca la `DealController::store()`. Un Agent care forjează
        // `owner_user_id` în cerere e refuzat aici, server-side, nu doar ascuns din UI.
        if (! Gate::allows('changeOwner', Order::class)) {
            unset($data['owner_user_id']);
        }

        $order = $action->execute($data, $request->user());

        return redirect()->route('orders.show', $order)->with('success', 'Order created.');
    }

    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('view', $order);

        RecentlyViewed::record(
            $request,
            'order',
            $order->getKey(),
            $order->order_number ?? ('#'.substr($order->id, -8)),
            route('orders.show', $order)
        );

        $order->load([
            'account:id,name',
            'deal:id,title',
            'owner:id,name',
            // §20.5 — bypass explicit, exact ca `DealController::show()`: un contact
            // anonimizat rămâne vizibil AICI (istoric al comenzii), altfel dispare
            // tăcut din pagină în loc să apară ca „Anonymized contact".
            'contact' => fn ($query) => $query
                ->withoutGlobalScope(NotAnonymizedContactScope::class)
                ->select(['id', 'first_name', 'last_name', 'anonymized_at']),
            'orderLines.variant:id,sku',
        ]);

        return Inertia::render('Orders/Show', [
            'order' => OrderResource::make($order),
            'can' => [
                'edit' => Gate::allows('update', $order),
                'delete' => Gate::allows('delete', $order),
                'confirm' => Gate::allows('confirm', $order),
                'cancel' => Gate::allows('cancel', $order),
            ],
        ]);
    }

    /**
     * `OrderPolicy::update()` refuză deja orice comandă care nu mai e `draft` — un
     * link direct spre editarea unei comenzi confirmate dă 403, nu un formular
     * inutilizabil.
     */
    public function edit(Request $request, Order $order): Response
    {
        Gate::authorize('update', $order);

        $order->load([
            'account:id,name',
            'contact' => fn ($query) => $query
                ->withoutGlobalScope(NotAnonymizedContactScope::class)
                ->select(['id', 'first_name', 'last_name', 'anonymized_at']),
            'orderLines.variant:id,sku',
        ]);

        $raw = $request->query('account');
        $accountId = is_string($raw) ? $raw : null;

        if ($request->has('account')) {
            $account = $accountId !== null && $accountId !== '' ? Account::query()->findOrFail($accountId) : null;
        } else {
            $account = $order->account;
        }

        return Inertia::render('Orders/Edit', [
            'order' => OrderResource::make($order),
            'account' => $account !== null ? ['id' => $account->id, 'name' => $account->name] : null,
            'contacts' => $this->contactsForAccount($account),
            'can' => [
                'changeOwner' => Gate::allows('changeOwner', $order),
                'delete' => Gate::allows('delete', $order),
            ],
            'owners' => Gate::allows('changeOwner', $order) ? $this->ownerOptions() : [],
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order, UpdateOrderLinesAction $action): RedirectResponse
    {
        Gate::authorize('update', $order);

        $data = $request->validated();

        // Code review P2-002 — vezi `store()`.
        if (! Gate::allows('changeOwner', $order)) {
            unset($data['owner_user_id']);
        }

        $action->execute($order, $data);

        return redirect()->route('orders.show', $order)->with('success', 'Order updated.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        Gate::authorize('delete', $order);

        $order->delete();

        return redirect()->route('orders.index')->with('success', 'Order deleted.');
    }

    private function ownerOptions(): AnonymousResourceCollection
    {
        return OrderOwnerOptionResource::collection(
            Membership::query()->where('status', Membership::STATUS_ACTIVE)->with('user:id,name')->get()
        );
    }

    private function contactsForAccount(?Account $account): AnonymousResourceCollection
    {
        if ($account === null) {
            return OrderContactOptionResource::collection(collect());
        }

        return OrderContactOptionResource::collection(
            $account->contacts()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
        );
    }
}
