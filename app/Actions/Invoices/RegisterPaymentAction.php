<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * US-BILL-02 — încasare parțială sau completă. `balance_due` e recalculat AICI, nu lăsat
 * pe seama unui observer/trigger: singurul loc care scrie `amount_paid`/`balance_due`,
 * la fel cum `ConfirmOrderAction` e singurul loc care scrie `reserved`.
 *
 * Blocarea e `lockForUpdate()` (FOR UPDATE), NU `for no key update`: spre deosebire de
 * numerotarea facturii (care blochează `tenants`, rândul PĂRINTE al întregului tenant —
 * `.ai/rules/tenancy.md`, „Blocarea unui rând părinte"), aici blocăm chiar rândul pe
 * care îl MODIFICĂM (`invoices`, un singur rând), exact cazul pe care regula îl declară
 * explicit acceptabil: „Pe rândul pe care CHIAR îl modifici… `lockForUpdate()` e
 * acceptabil: oprește doar copiii acelui rând" — copiii unei facturi sunt `payments`,
 * pe care oricum le inserăm noi, în aceeași tranzacție.
 */
final class RegisterPaymentAction
{
    /**
     * @param  array{amount: float, method: string, paid_at: \DateTimeInterface|string}  $data
     */
    public function execute(Invoice $invoice, array $data, User $recordedBy): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $recordedBy): Payment {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [Invoice::STATUS_SENT, Invoice::STATUS_OVERDUE], true)) {
                throw ValidationException::withMessages([
                    'status' => trans('rules.invoices.only_sent_or_overdue_can_receive_payment'),
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            $currentBalance = round((float) $locked->balance_due, 2);

            // Code review, apărare în adâncime — `StorePaymentRequest` validează deja
            // `amount <= balance_due`, dar balanța poate fi mai mică AICI dacă altă
            // plată a fost înregistrată concurent între validare și acest lock (același
            // TOCTOU generic pe care restul proiectului îl tratează cu blocare, nu doar
            // cu validare la intrare).
            if ($amount > $currentBalance) {
                // `:amount`/`:balance` deja formatate cu '$' — EXACT stringificarea folosită
                // înainte de mutare (interpolare directă a unui float rotunjit, nu
                // `number_format()`); formatarea locale-aware a monedei (FR-I18N-03) e a
                // Valului 3 (frontend), nu a acestui lot de backend.
                throw ValidationException::withMessages([
                    'amount' => trans('rules.invoices.payment_exceeds_balance', [
                        'amount' => '$'.$amount,
                        'balance' => '$'.$currentBalance,
                    ]),
                ]);
            }

            $payment = new Payment([
                'invoice_id' => $locked->getKey(),
                'amount' => $amount,
                'method' => $data['method'],
                'paid_at' => $data['paid_at'],
            ]);
            $payment->created_by = $recordedBy->getKey();
            $payment->save();

            $newAmountPaid = round((float) $locked->amount_paid + $amount, 2);
            $newBalance = round((float) $locked->total - $newAmountPaid, 2);

            $locked->amount_paid = $newAmountPaid;
            $locked->balance_due = max($newBalance, 0);

            // US-BILL-02 — „a doua plată... status devine paid automat".
            if ($locked->balance_due <= 0) {
                $locked->status = Invoice::STATUS_PAID;
            }

            $locked->save();

            return $payment;
        });
    }
}
