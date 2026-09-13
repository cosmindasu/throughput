<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use App\Models\Membership;
use App\Support\Contacts\DuplicateContactEmail;
use App\Support\Lists\AccountList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * US-CRM-01 — contul + contactul principal opțional, în același formular. FR-CRM-01:
 * validare server-side, nume obligatoriu, format telefon/email/domeniu.
 */
final class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^(([a-z0-9-]+)\.)+[a-z]{2,}$/i'],
            'industry' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'owner_user_id' => [
                'nullable',
                'string',
                Rule::exists('memberships', 'user_id')->where('status', Membership::STATUS_ACTIVE),
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
            'contact' => ['nullable', 'array'],
            'contact.first_name' => ['required_with:contact', 'string', 'max:255'],
            'contact.last_name' => ['required_with:contact', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'contact.title' => ['nullable', 'string', 'max:255'],
            'confirm_duplicate_email' => ['boolean'],
        ];
    }

    /**
     * US-CRM-01, al doilea scenariu: un email deja legat de alt cont oprește salvarea cu
     * un avertisment, nu cu un refuz definitiv — vezi `DuplicateContactEmail`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => DuplicateContactEmail::check(
            $validator,
            'contact.email',
            $this->input('contact.email'),
            $this->boolean('confirm_duplicate_email'),
        ));
    }
}
