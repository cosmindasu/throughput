<?php

namespace App\Http\Controllers\Web;

use App\Actions\Invoices\RegisterPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\StorePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * US-BILL-02 — înregistrarea manuală a unei încasări pe o factură (§12.1). Controller
 * cu un singur endpoint scriitor: nu există editare/ștergere de plăți (nicio cerință o
 * cere — o încasare greșită se corectează prin void pe factură, nu prin editarea unei
 * plăți deja înregistrate, ca să rămână trasabilă, BR-BILL-01).
 */
final class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Invoice $invoice, RegisterPaymentAction $action): RedirectResponse
    {
        Gate::authorize('create', [Payment::class, $invoice]);

        $action->execute($invoice, $request->validated(), $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Payment recorded.');
    }
}
