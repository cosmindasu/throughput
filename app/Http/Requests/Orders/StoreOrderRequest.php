<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Orders\Concerns\ValidatesOrderLineDiscount;
use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-ORD-01 — creare de comandă (draft), cu liniile inițiale opționale: BR-ORD-02/§11.2
 * cer minimum o linie doar la CONFIRMARE, nu la crearea draft-ului (§9 task punctul 2),
 * deci un draft se poate salva gol și primi linii ulterior din `edit()`.
 *
 * Autorizarea (`orders.create`) rămâne în controller cu `Gate::authorize()` — același
 * tipar ca `StoreDealRequest::authorize()`.
 */
class StoreOrderRequest extends FormRequest
{
    use ValidatesOrderLineDiscount;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'account_id' => [
                'required', 'string',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'contact_id' => [
                'nullable', 'string',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', $this->input('account_id'))
                    ->whereNull('anonymized_at')),
            ],
            'deal_id' => [
                'nullable', 'string',
                Rule::exists('deals', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', $this->input('account_id'))),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'owner_user_id' => [
                'nullable', 'string',
                Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', Membership::STATUS_ACTIVE)),
            ],
            'lines' => ['sometimes', 'array'],
            'lines.*.variant_id' => [
                'required_with:lines', 'string',
                Rule::exists('variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            // Code review P3 — discountul nu poate depăși subtotalul liniei
            // (`quantity * unit_price`), altfel `line_total`/`grand_total` ies negative.
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', $this->discountWithinLineSubtotalRule()],
        ];
    }
}
