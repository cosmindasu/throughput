<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

/**
 * Matricea §7.4, rândul „Plăți (înregistrare manuală)" (CRUD / CRUD / — / R). Spre
 * deosebire de `InvoicePolicy`, Agentul n-are NICIO permisiune `payments.*` în catalog
 * (`App\Support\Permissions::forRoles()`) — deci nu există nicio îngustare de proprietate
 * de scris aici, `can()` singur decide complet.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('payments.view');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view');
    }

    /** `Gate::authorize('create', [Payment::class, $invoice])` — pattern Laravel pentru „create" cu context suplimentar, ca `Gate::allows('create', [Shipment::class, $order])`. */
    public function create(User $user, Invoice $invoice): bool
    {
        return $user->can('payments.create');
    }
}
