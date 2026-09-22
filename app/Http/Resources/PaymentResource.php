<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O încasare înregistrată manual (US-BILL-02, §12.1) — randată în lista de plăți de pe
 * `Invoices/Show`.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            // FR-I18N-04, Lotul I18N Val 5 — trecea printr-un array PHP hardcodat
            // ('Bank transfer'/'Check'/'Manual'), randat neschimbat pe interfața franceză;
            // dropdown-ul formularului (`resources/js/locales/fr/invoices.json`,
            // `show.payments.methodOptions`) era deja tradus, deci cele două căi ale
            // aceluiași ecran divergeau. `ucfirst()` rămâne fallback-ul pentru o valoare
            // viitoare care n-are încă intrare în `enums.payment_method` — comportament
            // identic cu dinainte.
            'methodLabel' => in_array($this->method, Payment::methods(), true)
                ? __('enums.payment_method.'.$this->method)
                : ucfirst((string) $this->method),
            'paidAt' => $this->paid_at?->toIso8601String(),
            // FR-TEN-04 — placeholder „(deactivated)" pe cine a înregistrat plata.
            'createdBy' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => DeactivatedMemberNames::label($this->createdBy->name, $this->createdBy->id),
            ] : null),
        ];
    }
}
