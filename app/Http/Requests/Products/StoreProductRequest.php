<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §10.2 — creare produs. Fără variante aici: US-STOCK e construit pe variante existente,
 * iar prima variantă a unui produs nou se adaugă separat (`VariantController::store()`),
 * ca formularul să nu amestece două resurse într-un singur submit.
 */
final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'unit_of_measure' => ['required', Rule::in(['each', 'box', 'pallet'])],
            'is_active' => ['boolean'],
        ];
    }
}
