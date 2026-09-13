<?php

namespace App\Support\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Dreptul la ștergere (RTBF, Art. 17 GDPR — specs.md §20.5): ștergerea unui contact e
 * posibilă dacă nu are deals/orders asociate (consecvent cu BR-CRM-01); dacă are, se
 * anonimizează câmpurile identificabile, păstrând rândul (și integritatea referențială
 * a deal-urilor/comenzilor istorice).
 *
 * Separată de `ContactController::destroy()` ca să fie testabilă fără HTTP (plan §1.2).
 *
 * Tranzacție PROPRIE, nu cod turnat direct în controller: `Contact::query()->lockForUpdate()`
 * blochează rândul contactului pentru durata ei — un `INSERT` concurent pe `deals`/`orders`
 * ia automat un lock `FOR KEY SHARE` pe rândul referit de FK, deci așteaptă commit-ul de
 * aici, iar „citește dacă există referințe → decide → scrie" nu are o fereastră de cursă
 * în care un deal/order nou apare exact între citire și scriere.
 *
 * Capcană de mediu: Laravel nu emite `RELEASE SAVEPOINT` la commit-ul unei tranzacții
 * IMBRICATE (nivelul de aici, în tranzacția deschisă de middleware pe toată cererea —
 * `.ai/rules/project.md`), iar o eroare SQL prinsă într-un `catch` din codul apelant, FĂRĂ
 * să treacă prin rollback-ul la savepoint al lui `DB::transaction()`, lasă tranzacția
 * cererii abandonată (`25P02` la orice interogare ulterioară, inclusiv `COMMIT`-ul final).
 * De-aia întreaga decizie stă într-un SINGUR `DB::transaction()`, iar apelantul
 * (`ContactController::destroy()`) nu prinde nimic în jurul ei.
 */
final class ContactErasure
{
    /**
     * @return bool true dacă a fost anonimizat (are deals/orders), false dacă a fost șters fizic
     */
    public static function erase(string $contactId): bool
    {
        return DB::transaction(function () use ($contactId): bool {
            $contact = Contact::query()->lockForUpdate()->findOrFail($contactId);

            // Deals șterse (soft delete, §9.2) tot referă contul/contactul prin FK —
            // BR-CRM-01 le numără și pe alea, la fel face și garda de aici.
            $hasReferences = $contact->deals()->withTrashed()->exists()
                || $contact->orders()->exists();

            if (! $hasReferences) {
                $contact->delete();

                return false;
            }

            $contact->forceFill([
                'first_name' => 'Anonymized',
                'last_name' => 'contact',
                'email' => null,
                'phone' => null,
                'title' => null,
                'is_primary' => false,
                'opt_out' => true,
                'anonymized_at' => now(),
            ])->save();

            return true;
        });
    }
}
