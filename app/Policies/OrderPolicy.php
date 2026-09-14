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
            && ! $order->shipments()->exists();
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
}
