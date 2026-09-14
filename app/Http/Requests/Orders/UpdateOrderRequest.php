<?php

namespace App\Http\Requests\Orders;

use App\Models\Membership;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editarea unui draft — cont, contact, notițe, owner și liniile complete (înlocuite,
 * nu adăugate — vezi `UpdateOrderLinesAction`). `OrderPolicy::update()` refuză deja
 * orice comandă care nu mai e `draft`, deci validarea de aici nu mai repetă starea.
 */
class UpdateOrderRequest extends FormRequest
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
                    ->when(! $this->keepsExistingContact(), fn ($q) => $q->whereNull('anonymized_at'))),
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
            'lines' => ['present', 'array'],
            'lines.*.variant_id' => [
                'required', 'string',
                Rule::exists('variants', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }

    /**
     * Simetric cu `UpdateDealRequest::keepsExistingPrimaryContact()`: un contact deja
     * legat, anonimizat DUPĂ legare, rămâne valid pe o editare de rutină care nu-l
     * schimbă — altfel salvarea altui câmp l-ar goli tăcut.
     */
    private function keepsExistingContact(): bool
    {
        /** @var Order $order */
        $order = $this->route('order');

        return $order->contact_id !== null
            && $this->input('contact_id') === $order->contact_id
            && $this->input('account_id') === $order->account_id;
    }
}
