<?php

namespace App\Http\Requests\Contacts;

use App\Models\Contact;
use App\Support\Contacts\AccountBelongsToTenant;
use App\Support\Contacts\DuplicateContactEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * FR-CRM-02 — editare de contact, inclusiv mutarea între conturi (`account_id`
 * schimbat față de valoarea curentă). Vezi `StoreContactRequest` pentru motivul
 * pentru care `AccountBelongsToTenant` respinge singur un cont din alt tenant, pe
 * ambele straturi (ADR-003, P1-001).
 */
final class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Contact $contact */
        $contact = $this->route('contact');

        return $this->user()->can('update', $contact);
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
        /** @var Contact $contact */
        $contact = $this->route('contact');

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
            $contact->getKey(),
        ));
    }
}
