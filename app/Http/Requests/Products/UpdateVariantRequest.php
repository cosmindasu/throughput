<?php

namespace App\Http\Requests\Products;

use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('variant'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // P2-001 — vezi StoreVariantRequest: `Rule::unique()` ocolește global scope-ul,
        // deci tenantul se filtrează explicit, iar rândul curent se exclude prin `ignore`.
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('variants', 'sku')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($this->route('variant')),
            ],
            'attributes' => ['nullable', 'array'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['required', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
