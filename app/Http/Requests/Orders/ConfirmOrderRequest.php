<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-STOCK-04 — backorder-ul cere confirmare EXPLICITĂ, cerută de SERVER, nu doar
 * afișată în UI: un checkbox bifat pe client, dar necitit aici, ar face din regula de
 * business o decorație. `ConfirmOrderAction` decide dacă acest flag chiar era necesar
 * (o comandă fără nicio linie peste `available` nu are nevoie de el) — cererea doar
 * transportă intenția utilizatorului.
 */
class ConfirmOrderRequest extends FormRequest
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
        return [
            'acknowledge_backorder' => ['sometimes', 'boolean'],
        ];
    }

    public function acknowledgesBackorder(): bool
    {
        return $this->boolean('acknowledge_backorder');
    }
}
