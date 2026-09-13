<?php

namespace App\Http\Requests\Accounts;

use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use App\Support\Lists\AccountList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-CRM-01 — edit. Fără contact aici: contactele au CRUD propriu (alt pachet); §7.5
 * îngustează cine are voie, prin `AccountPolicy::update()` din `authorize()`.
 */
final class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // P2-001: vezi StoreAccountRequest — `Rule::exists()` ocolește global scope-ul
        // Eloquent, deci tenantul se filtrează explicit aici.
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^(([a-z0-9-]+)\.)+[a-z]{2,}$/i'],
            'industry' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'owner_user_id' => [
                'nullable',
                'string',
                Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', Membership::STATUS_ACTIVE)),
            ],
            'status' => ['required', Rule::in(AccountList::STATUSES)],
            'credit_terms' => ['required', Rule::in(['net_15', 'net_30', 'net_60', 'prepaid'])],
            'source' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:255'],
            'billing_address.city' => ['nullable', 'string', 'max:120'],
            'billing_address.state' => ['nullable', 'string', 'max:120'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country' => ['nullable', 'string', 'max:2'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'max:2'],
        ];
    }
}
