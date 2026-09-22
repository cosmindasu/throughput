<?php

namespace App\Support\Contacts;

use App\Models\Contact;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Inertia\Inertia;

/**
 * US-CRM-01, al doilea scenariu: un email deja legat de ALT cont nu blochează salvarea
 * definitiv — avertizează, cu link către contul existent, iar utilizatorul continuă
 * confirmând explicit („poate fi un contact legitim la două companii").
 *
 * Comun formularului de cont nou (contactul principal) și celui de contact, ca regula să
 * nu existe în două variante. Forma pe server e o eroare de validare pe câmp, deci cererea
 * se oprește și nimic nu se scrie până la confirmare. Datele pentru link (contul existent)
 * pleacă prin flash-ul Inertia, nu prin textul erorii, ca interfața să nu parseze mesaje.
 *
 * Emailul se compară normalizat la litere mici, exact cum se salvează: căutarea rămâne o
 * egalitate pe indexul `(tenant_id, email)`, nu un `lower(email)` care l-ar ocoli.
 */
final class DuplicateContactEmail
{
    public const FLASH_KEY = 'duplicateEmail';

    public static function check(
        Validator $validator,
        string $field,
        ?string $email,
        bool $confirmed,
        ?string $exceptContactId = null,
    ): void {
        if ($email === null || trim($email) === '' || $confirmed || $validator->errors()->has($field)) {
            return;
        }

        $existing = Contact::query()
            ->with('account:id,name')
            ->where('email', Str::lower(trim($email)))
            ->whereNotNull('account_id')
            ->when($exceptContactId !== null, fn ($query) => $query->whereKeyNot($exceptContactId))
            ->first();

        if ($existing === null) {
            return;
        }

        Inertia::flash(self::FLASH_KEY, [
            'field' => $field,
            'accountId' => $existing->account_id,
            'accountName' => $existing->account->name,
        ]);

        $validator->errors()->add($field, __('rules.contacts.email_already_linked', ['account' => $existing->account->name]));
    }
}
