<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §12.1 — „→ void, din orice stare, cu motiv": singurul câmp de intrare al acțiunii.
 * Autorizarea (`invoices.void`) rămâne în controller cu `Gate::authorize()`, la fel ca
 * `StoreOrderRequest`.
 */
class VoidInvoiceRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
