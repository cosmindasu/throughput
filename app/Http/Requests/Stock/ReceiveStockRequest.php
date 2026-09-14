<?php

namespace App\Http\Requests\Stock;

use App\Models\Scopes\TenantScope;
use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-STOCK-01 — recepție de marfă (`reason = receipt`, `delta` mereu pozitiv: câmpul
 * public e o cantitate, semnul îl adaugă `StockController::receive()`).
 */
final class ReceiveStockRequest extends FormRequest
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
        // P2-001 (code review, alte pachete) — `Rule::exists()` rulează SQL brut, care
        // ocolește global scope-ul Eloquent: fără `tenant_id` explicit, o locație dintr-un
        // ALT tenant ar trece validarea.
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'location_id' => [
                'required', 'string',
                Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
