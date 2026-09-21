<?php

namespace App\Http\Requests\Stock;

use App\Models\Scopes\TenantScope;
use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajustare manuală (`reason = adjustment`) — BR-STOCK-01: „notă obligatorie", cea
 * singura mișcare care poate merge în ambele sensuri (`delta` semnat, introdus direct,
 * nu o cantitate pozitivă ca la recepție).
 */
final class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockMovement::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'location_id' => [
                'required', 'string',
                Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'delta.not_in' => __('forms.stock.adjust.delta_not_in'),
            'note.required' => __('forms.stock.adjust.note_required'),
        ];
    }
}
