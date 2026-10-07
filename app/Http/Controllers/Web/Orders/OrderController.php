<?php

namespace App\Http\Controllers\Web\Orders;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderLinesAction;
use App\Enums\OrderStatus;
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
use App\Models\Shipment;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Bulk\BulkMatchingRowCount;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Exports\ExportFormat;
use App\Support\Exports\ListExport;
use App\Support\Lists\CursorPage;
use App\Support\Lists\OrderList;
use App\Support\RecentlyViewed;
use App\Support\SavedViews\ListColumns;
use App\Support\SavedViews\SavedViewDefaultRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
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
    public function index(Request $request, OrderList $list): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Order::class);

        // Selector de coloane (specs.md §15.1, D2) — vezi docblock-ul echivalent din
        // `AccountController::index()`: implicitul salvat câștigă în fața filtrului de rol,
        // dar doar pe un URL fără NIMIC explicit încă (filtru, sortare, cursor SAU coloane).
        if (($redirect = SavedViewDefaultRedirect::resolve($request, 'orders')) !== null) {
            return $redirect;
        }

        $user = $request->user();
        $query = $list->parse($request);
        $paginator = $query->paginate($list->query($query, $user));

        return Inertia::render('Orders/Index', [
            'orders' => Inertia::defer(fn () => CursorPage::make(
                $paginator,
                OrderSummaryResource::class,
            )),
            // Pachetul C („bulk"), lotul E — vezi docblock-ul echivalent din
            // `AccountController::index()`: N-ul EXACT pe care operația de REASIGNARE l-ar
            // atinge pe filtrul curent, cu restricția de proprietate a Agentului deja
            // aplicată (P2-003, `BulkMatchingRowCount`).
            'total' => Inertia::defer(fn () => BulkMatchingRowCount::for(
                $user,
                BulkWritableResources::resolve('orders'),
                $list->query($query, $user),
            )),
            // Distinct de `total` de mai sus: anularea în masă atinge DOAR `draft`
            // (§13.5) — „Select all N draft orders" și pragul de confirmare al ACELEI
            // acțiuni trebuie să numere rândurile efectiv afectate, nu tot filtrul (defectul
            // (g) din v1.24, reprodus altfel dacă `draftTotal` ar lipsi și bara ar folosi
            // `total` pentru amândouă acțiunile). Aceeași îngustare ca la dispatch
            // (`BulkChunkActions::narrowQuery`), o singură expresie.
            'draftTotal' => Inertia::defer(fn () => BulkMatchingRowCount::for(
                $user,
                BulkWritableResources::resolve('orders'),
                BulkChunkActions::narrowQuery(BulkChunkActions::CANCEL_DRAFT_ORDERS, $list->query($query, $user)),
            )),
            'filters' => $query->toArray(),
            // Selector de coloane (specs.md §15.1) — validate server-side ca orice filtru;
            // un `?columns=` necunoscut/gol cade pe `SavedViewResourceType::defaultColumns()`.
            'columns' => ListColumns::fromRequest($request, 'orders'),
            'can' => [
                'create' => Gate::allows('create', Order::class),
                // Separat de `bulkReassignOwner`/`bulkCancelDrafts` (§7.4 nota ³, §13.5):
                // exportul e o citire, permisă și Viewer-ului.
                'export' => Gate::allows('export', Order::class),
                'bulkReassignOwner' => Gate::allows('bulkReassignOwner', Order::class),
                'bulkCancelDrafts' => Gate::allows('bulkCancel', Order::class),
            ],
            // Gol când reasignarea nu e permisă — ca la `create()`/`edit()` mai jos.
            'owners' => Gate::allows('bulkReassignOwner', Order::class) ? $this->ownerOptions() : [],
            'bulkConfirmationThreshold' => BulkConfirmationThreshold::for($user),
            'bulkRowCap' => BulkConfirmationThreshold::rowCapForRole($user),
        ]);
    }

    /**
     * US-CRM-03, §13.2/§13.5 — exact interogarea ecranului curent (`OrderList` pe URL-ul
     * curent), sincron sub prag pentru CSV, job în coadă peste el; PDF-ul e mereu în coadă
     * (`ListExport::respond()`). Ruta e declarată ÎNAINTEA `orders/{order}`
     * (routes/web/orders.php), altfel „export" ar fi interpretat ca un id de comandă.
     *
     * `ExportFormat::fromRequest()` (code review P2) — `format` absent → CSV; orice altă
     * valoare necunoscută (`xlsx`, „PDF" cu majusculă) → 422 explicit, nu o cădere tăcută
     * pe CSV.
     */
    public function export(Request $request): RedirectResponse|HttpResponse
    {
        Gate::authorize('export', Order::class);

        return app(ListExport::class)->respond($request, 'orders', ExportFormat::fromRequest($request));
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

        return redirect()->route('orders.show', $order)->with('success', __('flash.orders.created'));
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
            // Faza 3, valul 2 (§11.2 pas 4) — `orderLines.shipmentLines.shipment` alimentează
            // `OrderLineResource::remainingToShip()` (`RemainingToShip::forLine()`) FĂRĂ N+1;
            // `shipments.shipmentLines.orderLine` alimentează secțiunea Shipments + timeline
            // (FR-ORD-03).
            'orderLines.variant:id,sku',
            'orderLines.shipmentLines.shipment',
            'shipments' => fn ($query) => $query->latest('created_at')->latest('id'),
            'shipments.shipmentLines.orderLine:id,description',
        ]);

        // `ShipmentResource` evaluează `retryLabel`/`markShipped` pe fiecare shipment, iar
        // politica citește `$shipment->order`, adică exact comanda de pe pagină. Fără
        // relația inversă setată aici, o interogare per shipment (prins de E2E sub PERF-03).
        // Local, nu `chaperone()` pe `Order::shipments()`: o referință circulară globală ar
        // ajunge și în serializarea joburilor.
        $order->shipments->each->setRelation('order', $order);

        return Inertia::render('Orders/Show', [
            'order' => OrderResource::make($order),
            'can' => [
                'edit' => Gate::allows('update', $order),
                'delete' => Gate::allows('delete', $order),
                // ȘI starea, nu doar dreptul. `OrderPolicy::confirm()` verifică DELIBERAT
                // numai permisiunea — o tranziție e o regulă de stare, refuzată de
                // `ConfirmOrderAction` cu un mesaj pe câmp, nu cu un 403 opac. Corect pentru
                // autorizare, insuficient pentru randare: pagina unei comenzi deja livrate
                // afișa insigna „Fulfilled" lângă un buton „Confirm order" activ, care la
                // click producea o eroare de validare. Contractul din README e „un buton la
                // care n-ai dreptul e ABSENT, nu dezactivat"; aici dreptul exista, dar
                // acțiunea nu mai era posibilă — tot absent trebuie să fie.
                'confirm' => $order->status === OrderStatus::Draft && Gate::allows('confirm', $order),
                'cancel' => Gate::allows('cancel', $order),
                // Faza 3, valul 2 — `Gate::authorize('create', [Shipment::class, $order])`,
                // pattern Laravel pentru „create" cu context suplimentar (comanda).
                'createShipment' => Gate::allows('create', [Shipment::class, $order]),
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

        return redirect()->route('orders.show', $order)->with('success', __('flash.orders.updated'));
    }

    public function destroy(Order $order): RedirectResponse
    {
        Gate::authorize('delete', $order);

        $order->delete();

        return redirect()->route('orders.index')->with('success', __('flash.orders.deleted'));
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
