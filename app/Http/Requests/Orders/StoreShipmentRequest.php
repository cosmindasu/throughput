<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * US-ORD-02 — „Create shipment": cantități per linie, alegerea parțial permisă (nu
 * fiecare linie a comenzii trebuie inclusă). Validarea de DOMENIU (cantitatea nu
 * depășește rămasul, linia chiar aparține acestei comenzi) rămâne în
 * `CreateShipmentAction`, la fel ca `ConfirmOrderRequest`/`ConfirmOrderAction`: cererea
 * transportă forma, acțiunea decide regula de business.
 *
 * `authorize()` e `true` — dreptul se verifică în controller
 * (`Gate::authorize('create', [Shipment::class, $order])`), simetric cu
 * `ConfirmOrderRequest`.
 */
class StoreShipmentRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, int> order_line_id => cantitate
     */
    public function quantities(): array
    {
        /** @var array<string, int> $lines */
        $lines = $this->validated('lines');

        return $lines;
    }
}
