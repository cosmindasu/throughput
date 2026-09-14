<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §10.2 — `sku` unic per tenant. Varianta se creează pe un produs dat de rută
 * (`/products/{product}/variants`), deci `product_id` nu e câmp de formular.
 */
final class StoreVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Variant::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // P2-001 (code review, alte pachete) — `Rule::unique()` rulează SQL brut, care
        // ocolește global scope-ul Eloquent (`BelongsToTenant`): fără `tenant_id` explicit,
        // un SKU deja folosit într-un ALT tenant ar bloca eronat validarea aici.
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('variants', 'sku')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'attributes' => ['nullable', 'array'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['required', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /** Injectat de `VariantController::store()` — vezi docblock-ul clasei. */
    public function product(): Product
    {
        return $this->route('product');
    }
}
