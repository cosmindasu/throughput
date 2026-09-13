<?php

namespace App\Support\Contacts;

use App\Models\Account;
use App\Models\Contact;

/**
 * FR-CRM-02 — un singur contact `is_primary` per cont, aplicat la salvare.
 *
 * `Account::lockForUpdate()` blochează rândul contului pentru durata tranzacției
 * apelantului: dacă două cereri concurente marchează primary pe DOI contacte
 * diferite ale ACELUIAȘI cont, a doua așteaptă commit-ul primei, deci vede deja
 * retrogradarea ei și nu lasă doi primari. Fără acest lock, „read primary
 * existent → retrogradează → salvează noul primary" ar avea o fereastră de cursă:
 * ambele cereri citesc „niciun primary încă", ambele salvează, ambele rămân true.
 *
 * Blocarea contului, nu a contactelor lui: contul e resursa comună pe care se ceartă
 * cele două salvări, iar contactul nou/mutat poate fi o inserare (fără rând încă de
 * blocat). Acoperă și mutarea unui contact primary de pe un cont pe altul — apelantul
 * dă mereu contul ȚINTĂ (cel după salvare), nu cel vechi.
 */
final class PrimaryContactAssignment
{
    public static function apply(string $accountId, ?string $exceptContactId = null): void
    {
        Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

        Contact::query()
            ->where('account_id', $accountId)
            ->where('is_primary', true)
            ->when($exceptContactId !== null, fn ($query) => $query->whereKeyNot($exceptContactId))
            ->update(['is_primary' => false]);
    }
}
