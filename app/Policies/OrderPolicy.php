<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Comenzi" (CRUD / CRUD / CRUD* / R), plus ABAC punctual din §7.5.
 *
 * Simetric cu `DealPolicy`: îngustarea de proprietate se uită DOAR la `owner_user_id`
 * (un Agent care creează o comandă devine automat owner-ul ei, `CreateOrderAction`).
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('orders.view');
    }

    public function create(User $user): bool
    {
        return $user->can('orders.create');
    }

    /**
     * §7.5, rândul InvoicePolicy e analogul explicit citat de specs: „o factură emisă
     * (status != draft) nu mai poate fi editată pe linii — doar anulată (void)". Aceeași
     * regulă se aplică aici, pe comenzi: liniile unei comenzi ies din discuție odată ce
     * `reserved` a fost alocat la confirmare (§10.5) — o editare necontrolată a
     * cantităților ar decupla `order_lines` de `inventory_levels.reserved` fără nicio
     * mișcare de stoc care s-o repare. Singura cale înainte, după confirmare, e
     * `cancel()`.
     */
    public function update(User $user, Order $order): bool
    {
        return $user->can('orders.edit')
            && $this->isWithinOwnRecords($user, $order)
            && $order->status === OrderStatus::Draft;
    }

    /**
     * BR-ORD-02/§11.3 — dreptul de a confirma o comandă (`orders.edit` + proprietate,
     * ca la `update()`), FĂRĂ verificarea stării: la fel ca `DealPolicy::moveStage()`,
     * o tranziție e o regulă de STARE, verificată de acțiune (`ConfirmOrderAction`
     * refuză cu `ValidationException` dacă `status` nu mai e `Draft`), nu de Policy.
     * Confundarea celor două ar transforma un refuz clar pe câmp într-un 403 opac
     * pentru un utilizator care doar a dat dublu-click.
     */
    public function confirm(User $user, Order $order): bool
    {
        return $user->can('orders.edit') && $this->isWithinOwnRecords($user, $order);
    }

    /**
     * O comandă `draft` nedorită se poate șterge direct; dincolo de `draft`,
     * `reserved`/`order_number` există deja — calea de ieșire e `cancel()`, nu `delete()`.
     */
    public function delete(User $user, Order $order): bool
    {
        return $user->can('orders.delete')
            && $this->isWithinOwnRecords($user, $order)
            && $order->status === OrderStatus::Draft;
    }

    /**
     * BR-ORD-01 / §7.5: „o comandă nu mai poate fi anulată integral după ce are cel
     * puțin un shipment — `OrderPolicy::cancel()` verifică `shipments()->exists()`".
     * Validarea STĂRII (comanda chiar poate tranziționa la `cancelled` acum, adică nu
     * e deja `cancelled`/`fulfilled`) rămâne în `CancelOrderAction`, ca la deals — un
     * Policy răspunde la drept, nu la stare curentă exactă.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->can('orders.cancel')
            && $this->isWithinOwnRecords($user, $order)
            && ! $this->hasShipments($order);
    }

    /**
     * Code review P2-002 — simetric cu `DealPolicy::changeOwner()`. Doar permisiunea,
     * nicio îngustare ABAC: în matricea de roluri (`Permissions::forRoles()`)
     * `orders.change_owner` există doar la Owner/Manager, niciodată la Agent, deci
     * proprietatea curentă a comenzii n-are cum să schimbe rezultatul.
     *
     * `?Order $order = null`: `Gate::authorize('changeOwner', Order::class)` (pagina
     * Create, unde încă nu există nicio comandă) scurtează argumentul la un singur
     * parametru (`$user`) — vezi `Gate::callPolicyMethod()`. Aceeași metodă acoperă și
     * Show/Edit, unde se cheamă cu instanța reală.
     */
    public function changeOwner(User $user, ?Order $order = null): bool
    {
        return $user->can('orders.change_owner');
    }

    /**
     * §13.4/§13.5 (Pachetul C, valul „bulk", lotul E) — reasignare owner în masă pe
     * Orders, simetric cu `AccountPolicy`/`DealPolicy::bulkReassignOwner()`. Legată de
     * `orders.change_owner`, ca `changeOwner()` de mai sus: doar Owner/Manager au
     * permisiunea în catalog. Îngustarea la subsetul propriu (BR-BULK-02, Agent — dar
     * Agentul n-are `orders.change_owner`, deci nici pe Orders nu ajunge aici, la fel ca
     * pe Deals) e la nivel de INTEROGARE (`OrderBulkResource::scopeToOwnRecords()`), nu în
     * Policy. Folosită de mecanismul generic (§13.2) ȘI de reatribuirea la dezactivarea
     * unui membru (US-TEN-03, BR-BULK-04), unde comenzile sunt al treilea tip din același
     * `group_id`.
     */
    public function bulkReassignOwner(User $user): bool
    {
        return $user->can('orders.change_owner') && $user->can('bulk.write');
    }

    /**
     * §13.5 — anulare în masă a comenzilor `draft`. Legată de `orders.cancel`, PERMISIUNE
     * pe care Agentul O ARE (spre deosebire de `orders.change_owner`) — un Agent poate
     * anula în masă draft-urile lui, cu plafonul de rânduri BR-BULK-02, verificat separat
     * în `DispatchBulkOperationAction`, ca la reasignare. Fără îngustare ABAC aici: la fel
     * ca `bulkReassignOwner()`, subsetul propriu al Agentului e la nivel de interogare
     * (`OrderBulkResource::scopeToOwnRecords()`), nu de Policy.
     */
    public function bulkCancel(User $user): bool
    {
        return $user->can('orders.cancel') && $user->can('bulk.write');
    }

    /**
     * §7.4 nota ³, §13.5 (BR-BULK-03) — exportul e o CITIRE, permisă și Viewer-ului:
     * simetric cu `AccountPolicy::export()`.
     */
    public function export(User $user): bool
    {
        return $user->can('orders.view') && $user->can('bulk.export');
    }

    /**
     * §7.5: un Agent lucrează doar pe comenzile unde e responsabil (ca la Deals/Accounts).
     */
    private function isWithinOwnRecords(User $user, Order $order): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        return $order->owner_user_id === $user->getKey();
    }

    /**
     * Code review P2-001 — `OrderList::baseQuery()` precarcă `shipments_exists` cu
     * `withExists('shipments')` pentru fiecare rând din `Orders/Index`: fără el,
     * `OrderSummaryResource` chema `Gate::allows('cancel', …)` per rând, iar acest
     * cod repeta `shipments()->exists()` de fiecare dată — 50 de interogări în plus
     * pe o pagină de 50. Aici, folosește atributul deja încărcat când există; cade pe
     * interogarea directă în celelalte locuri unde `cancel()` se verifică pe UN
     * singur `Order` (Show, `CancelOrderController`), unde un `exists()` nu costă
     * nimic în plus.
     */
    private function hasShipments(Order $order): bool
    {
        if (array_key_exists('shipments_exists', $order->getAttributes())) {
            return (bool) $order->shipments_exists;
        }

        return $order->shipments()->exists();
    }
}
