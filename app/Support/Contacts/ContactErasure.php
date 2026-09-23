<?php

namespace App\Support\Contacts;

use App\Jobs\System\MaskErasedContactActivityLogJob;
use App\Models\Contact;
use App\Models\Scopes\TenantScope;
use App\Support\Activity\ActivityLogAnonymizer;
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
 *
 * **GDPR-02 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`)**: ștergerea/
 * anonimizarea de mai sus nu se oprea la rândul `contacts` — rândurile ISTORICE din
 * `activity_log` (create/update-uri anterioare ale acestui contact) rămâneau cu
 * numele/emailul/telefonul original în clar, vizibile în ecranul „Activity" până la
 * anonimizarea de retenție de la 36 de luni (`App\Jobs\System\AnonymizeActivityLogJob`).
 * Ambele ramuri de mai jos mascară acum și acele rânduri, prin `ActivityLogAnonymizer`
 * (același mecanism SQL folosit de jobul de retenție lunară — vezi docblock-ul lui).
 *
 * Rândul NOU, scris ASINCRON de evenimentul `updated`/`deleted` declanșat chiar de
 * operația de mai jos (`forceFill()->save()`/`delete()`), NU există încă în `activity_log`
 * în momentul acestui apel — un query aici tot nu l-ar prinde. Acela e mascat la SURSĂ,
 * sincron, în `App\Observers\ActivityLogObserver` (vezi docblock-ul lui pentru motivul
 * cursei asincrone și alternativa aleasă).
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

                self::maskActivityLog($contactId);

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

            self::maskActivityLog($contactId);

            return true;
        });
    }

    /**
     * GDPR-02 — mascarea rândurilor PREEXISTENTE din `activity_log` pentru acest contact,
     * indiferent de vechime (spre deosebire de `AnonymizeActivityLogJob`, care mască doar
     * ce a trecut de pragul de retenție). Filtrul explicit pe `auditable_id` ține mascarea
     * strict la ACEST contact — un alt contact din același tenant, sau unul din alt
     * tenant, nu e atins (interogarea rulează oricum sub RLS-ul tenantului curent).
     */
    private static function maskActivityLog(string $contactId): void
    {
        ActivityLogAnonymizer::anonymize(
            DB::table('activity_log')
                ->where('auditable_type', Contact::class)
                ->where('auditable_id', $contactId),
        );

        // Rândurile încă în coadă în acest moment (modificări anterioare, nedrenate) se scriu
        // după commit — le prinde plasa de siguranță, vezi docblock-ul jobului.
        MaskErasedContactActivityLogJob::dispatch((string) TenantScope::currentTenantId(), $contactId)
            ->afterCommit()
            ->delay(MaskErasedContactActivityLogJob::DELAY_SECONDS);
    }
}
