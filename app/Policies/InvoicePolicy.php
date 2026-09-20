<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Facturi (către clienți)" (CRUD / CRUD / R* / R).
 *
 * Simetric cu `OrderPolicy`: îngustarea de proprietate pentru Agent se uită la
 * `owner_user_id` al COMENZII sursă (`Invoice` n-are propriul owner — e un document
 * derivat, §12.1), nu la un câmp propriu.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.view') && $this->isWithinOwnRecords($user, $invoice);
    }

    /**
     * `?Order $order = null` — la fel ca `OrderPolicy::changeOwner()`: verificarea
     * dreptului brut (butonul „Create Invoice" de pe `Orders/Show`, unde comanda EXISTĂ
     * deja) trece un `Order` real; catalogul (`invoices.create`) există doar la
     * Owner/Manager (§7.4), care n-au nicio îngustare de proprietate — deci ownership-ul
     * comenzii nu schimbă niciodată rezultatul aici, spre deosebire de `view()`/`void()`.
     */
    public function create(User $user, ?Order $order = null): bool
    {
        return $user->can('invoices.create');
    }

    /**
     * §7.5 — „O factură emisă (status != draft) nu mai poate fi editată, doar anulată
     * (void) | InvoicePolicy::update()": citat EXPLICIT de specs.md ca locul care aplică
     * BR-BILL-01. Folosită și pentru gate-ul de „Mark as sent" (o tranziție PORNIND de la
     * draft e, prin definiție, singura formă de „editare" pe care o factură draft o mai
     * suportă).
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.edit') && $invoice->status === Invoice::STATUS_DRAFT;
    }

    /** §12.1 — „→ void, din orice stare, cu motiv": fără verificare de stare aici, la fel ca `OrderPolicy::confirm()` — tranziția (deja void?) e regula acțiunii (`VoidInvoiceAction`), nu a dreptului. */
    public function void(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.void');
    }

    /** Reîncercarea generării PDF-ului — aceeași permisiune ca „edit", fără restricția de stare `draft`: se aplică pe orice factură cu `pdf_status = failed`, indiferent de status-ul de business. */
    public function retryPdf(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.edit');
    }

    private function isWithinOwnRecords(User $user, Invoice $invoice): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        $invoice->loadMissing('order:id,owner_user_id');

        return $invoice->order?->owner_user_id === $user->getKey();
    }
}
