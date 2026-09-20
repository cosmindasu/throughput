<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-BILL-02 — înregistrarea unei încasări (parțială sau completă). `amount` e limitat
 * la `balance_due`-ul CURENT al facturii (route model binding, `{invoice}`): un
 * overpayment se refuză aici, la intrare — `RegisterPaymentAction` re-verifică sub
 * blocare (TOCTOU, vezi docblock-ul acțiunii), pentru cazul unei a doua plăți
 * înregistrate concurent, între această validare și acel lock.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0, (float) $invoice->balance_due)],
            'method' => ['required', Rule::in(Payment::methods())],
            // O plată se ÎNREGISTREAZĂ (constatativ — s-a încasat deja), nu se programează:
            // o dată în viitor n-ar avea sens pentru un transfer/cec deja primit.
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
