<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Support\Contacts\AccountBelongsToTenant;
use App\Support\Contacts\DuplicateContactEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * FR-CRM-02 — creare de contact. `account_id` opțional (lead brut, §8.1) dar, dacă
 * prezent, trebuie să fie un cont VIZIBIL în tenantul curent: `AccountBelongsToTenant`
 * validează prin Eloquent (global scope + RLS, ADR-003, P1-001) — un id dintr-un alt
 * tenant nu există pentru NICIUN strat, deci cererea pică validarea cu 422, nu leagă
 * contactul greșit.
 */
final class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Contact::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_id' => $this->filled('account_id') ? trim((string) $this->input('account_id')) : null,
            'email' => $this->filled('email') ? Str::lower(trim((string) $this->input('email'))) : null,
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['nullable', 'string', new AccountBelongsToTenant],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'opt_out' => ['sometimes', 'boolean'],
            'confirm_duplicate_email' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('is_primary') && $this->input('account_id') === null) {
                $validator->errors()->add('is_primary', __('rules.contacts.primary_requires_account'));
            }
        });

        $validator->after(fn (Validator $validator) => DuplicateContactEmail::check(
            $validator,
            'email',
            $this->input('email'),
            $this->boolean('confirm_duplicate_email'),
        ));
    }
}
