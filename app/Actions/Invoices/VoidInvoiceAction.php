<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §12.1 — „→ void (din orice stare, cu motiv)". `InvoicePolicy::void()` verifică doar
 * dreptul (`invoices.void`, Owner/Manager); starea „nu e deja void" e o regulă de
 * TRANZIȚIE, verificată aici, la fel ca `ConfirmOrderAction`/`MarkInvoiceSentAction` —
 * un Policy răspunde la drept, nu la starea curentă exactă (§7.5, docblock-ul
 * `OrderPolicy::confirm()`).
 *
 * Nu atinge `amount_paid`/`balance_due`: o factură anulată păstrează istoricul
 * încasărilor deja înregistrate (trasabilitate, BR-BILL-01) — „void" înseamnă „nu mai
 * urmărim de-a lungul timpului acest AR", nu „ștergem ce s-a încasat deja".
 */
final class VoidInvoiceAction
{
    public function execute(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoid()) {
                throw ValidationException::withMessages([
                    'status' => 'This invoice is already void.',
                ]);
            }

            $locked->status = Invoice::STATUS_VOID;
            $locked->void_reason = $reason;
            $locked->voided_at = now();
            $locked->save();

            return $locked->fresh(['order.account', 'payments']);
        });
    }
}
