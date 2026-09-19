<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Onorare / expediere" (Owner/Manager CRUD, Agent CU* pe comenzi
 * PROPRII, Viewer R). Simetric cu `OrderPolicy`: DREPTUL aici, STAREA (shipment-ul chiar
 * poate trece din `label_failed` în `label_pending` ACUM, de exemplu) rămâne în acțiune,
 * ca `ValidationException`, nu un 403 opac — același principiu, aceeași notă în
 * `OrderPolicy::confirm()`.
 *
 * `view`/`viewAny` NU îngustează la proprietate — la fel ca `OrderPolicy::view()`
 * (docblock-ul de acolo: „un Agent poate vedea orice comandă din workspace, dar editează/
 * confirmă/anulează doar pe cele proprii"). Un Agent care se uită la o comandă a altcuiva
 * vede și shipment-urile ei; doar acțiunile de scriere se îngustează.
 */
class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('shipments.view');
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.view');
    }

    /**
     * `Gate::authorize('create', [Shipment::class, $order])` — shipment-ul încă nu
     * există, `$order` e contextul (pattern documentat Laravel pentru „create" cu
     * argument suplimentar).
     */
    public function create(User $user, Order $order): bool
    {
        return $user->can('shipments.create') && $this->isWithinOwnRecords($user, $order);
    }

    /**
     * US-ORD-03 — reîncercarea manuală a etichetei pe un shipment `label_failed`.
     */
    public function retryLabel(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.edit') && $this->isWithinOwnRecords($user, $shipment->order);
    }

    /**
     * §11.2 pas 5 — marcarea „shipped" (`MarkShipmentShippedAction`).
     */
    public function markShipped(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.edit') && $this->isWithinOwnRecords($user, $shipment->order);
    }

    /**
     * Renunțarea la un shipment `label_failed` (`DiscardShipmentAction`) — matricea NU dă
     * „D" Agentului (doar „CU*"), deci `shipments.delete` nu există pe rolul Agent în
     * `Permissions::forRoles()`: un Agent nu poate renunța nici la propriul shipment eșuat,
     * doar îl reîncearcă.
     */
    public function discard(User $user, Shipment $shipment): bool
    {
        return $user->can('shipments.delete') && $this->isWithinOwnRecords($user, $shipment->order);
    }

    /**
     * §7.5, tiparul deja stabilit de `OrderPolicy`/`DealPolicy`: îngustarea de proprietate
     * se uită la `owner_user_id` AL COMENZII (nu la cine a creat shipment-ul — shipment-urile
     * nu au propriul `owner_user_id`, matricea vorbește de „comenzi proprii").
     */
    private function isWithinOwnRecords(User $user, Order $order): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        return $order->owner_user_id === $user->getKey();
    }
}
