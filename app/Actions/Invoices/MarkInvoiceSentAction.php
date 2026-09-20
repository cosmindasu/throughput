<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-BILL-01, deuxième moitié: „Mark as sent" — `draft -> sent`, `due_date` calculat din
 * `accounts.credit_terms` (§12.1). `InvoicePolicy::update()` deja restrânge acest drept
 * la `status === draft` (§7.5: „InvoicePolicy::update()" e citat EXPLICIT ca locul care
 * aplică regula), deci verificarea de stare de aici e apărare în adâncime — același
 * tipar ca `ConfirmOrderAction`, care re-verifică tranziția chiar dacă Policy-ul a lăsat
 * deja cererea să treacă.
 */
final class MarkInvoiceSentAction
{
    /** @var array<string, int> Copie a `Database\Seeders\Demo\BillingSeeder::CREDIT_TERM_DAYS` — aceeași sursă de adevăr (specs.md §8.1). */
    private const CREDIT_TERM_DAYS = [
        'net_15' => 15,
        'net_30' => 30,
        'net_60' => 60,
        'prepaid' => 0,
    ];

    public function execute(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Only a draft invoice can be marked as sent.',
                ]);
            }

            $locked->load('order.account');
            $termDays = self::CREDIT_TERM_DAYS[$locked->order?->account?->credit_terms] ?? 30;

            $issueDate = $locked->issue_date ?? now();

            $locked->status = Invoice::STATUS_SENT;
            $locked->due_date = $issueDate->copy()->addDays($termDays);
            $locked->save();

            return $locked->fresh(['order.account', 'payments']);
        });
    }
}
