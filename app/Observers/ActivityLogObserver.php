<?php

namespace App\Observers;

use App\Events\Activity\ModelWasRecorded;
use App\Models\Contact;
use App\Models\Scopes\TenantScope;
use App\Support\Activity\ActivityLogAnonymizer;
use App\Support\Activity\ChangedAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-007, specs.md §17 — un singur observer generic, ATAȘAT de `App\Providers\
 * ActivityLogServiceProvider` pe fiecare model de business relevant (Account, Contact,
 * Deal, Product, Variant, Order — vezi docblock-ul provider-ului pentru lista completă și
 * motivul ei), în loc de un observer PER model: cele trei metode de mai jos nu depind de
 * NIMIC specific unui model anume, deci o clasă per model ar fi fost cod repetat fără
 * niciun beneficiu — modelele rămân neatinse (niciun `#[ObservedBy]`, vezi motivul din
 * raportul lotului: `app/Models/Invoice.php` etc. sunt editate ÎN PARALEL de alte loturi).
 *
 * Rulează SINCRON, în firul cererii — de aici captura de `request()->ip()`/`userAgent()`
 * AICI, nu în listener (care rulează pe coadă, unde cererea originală nu mai există).
 * Singurul lucru pe care acest observer îl face e să CONSTRUIASCĂ evenimentul cu scalari
 * și să-l dispecerizeze; scrierea propriu-zisă e a listener-ului pe coadă
 * (`App\Listeners\Activity\WriteActivityLogEntry`), per ADR-007.
 *
 * **GDPR-02 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`) — cursa
 * asincronă la erasure de Contact**: `App\Support\Contacts\ContactErasure` mască rândurile
 * PREEXISTENTE din `activity_log` la momentul ștergerii, dar rândul scris de EVENIMENTUL
 * `updated`/`deleted` declanșat de erasure-ul însuși încă nu există la acel moment —
 * `record()` de mai jos abia îl CONSTRUIEȘTE aici (sincron), iar
 * `WriteActivityLogEntry` îl scrie efectiv mai târziu, pe coadă (asincron, posibil la
 * secunde/minute distanță, după ce tranzacția de erasure a comis deja). Fără nimic în
 * plus, acel rând ar fi singurul loc din toată aplicația unde PII-ul „șters" mai
 * supraviețuiește în clar.
 *
 * Alternative posibile și de ce s-a ales asta:
 *  (a) **[ALEASĂ]** mască `old_values`/`new_values` ÎN OBSERVER, chiar la construcția
 *      evenimentului, când tranziția e recognoscibilă generic din STAREA modelului
 *      (`Contact.anonymized_at: null → setat` pentru `updated`; orice `deleted` pe
 *      `Contact`, singura cale fiind `ContactErasure` — vezi `isContactErasureTransition()`
 *      și docblock-ul de la `deleted()`). Nu cere nimic pe coadă, nu cere un al doilea
 *      job, iar rândul scris de listener e IDENTIC cu ce ar fi produs oricum
 *      `AnonymizeActivityLogJob` peste 36 de luni — un invariant simplu de verificat.
 *  (b) un job de „plasă" dispecerizat DUPĂ commit din `ContactErasure`, care re-mască
 *      rândurile contactului ca să prindă și evenimente scrise de joburi deja în coadă
 *      DINAINTE de erasure (backlog nedrenat pe worker-ul unic FIFO al proiectului).
 *      Util în teorie, dar cere un job NOU, de sistem — în afara feliei acestui lot
 *      (fișierele atinse sunt enumerate explicit) — semnalat separat în raportul lotului,
 *      nu implementat aici. Riscul rezidual: dacă exact la momentul unui `erase()` mai
 *      există, nedrenat, un job de scriere pentru o modificare ANTERIOARĂ a ACELUIAȘI
 *      contact, acel rând s-ar scrie ulterior cu PII-ul de dinainte de erasure, iar
 *      mascarea din `ContactErasure` (care rulează ÎN tranzacția de erasure, deci
 *      înaintea acelui job din coadă) nu l-ar prinde. Loturile care ating
 *      `app/Jobs/System/` pot închide acest rest cu un job dedicat.
 *
 * Mascarea e limitată STRICT la tranziția de erasure a unui `Contact` — un update obișnuit
 * pe un contact (ex: schimbare de telefon din UI) sau orice altă entitate observată
 * (Account, Deal, Product, Variant, Order, Invoice) nu trece prin `isContactErasureTransition()`
 * și scrie jurnalul neschimbat, ca până acum.
 */
final class ActivityLogObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', null, ChangedAttributes::snapshot($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changedKeys = array_keys($model->getChanges());

        if ($changedKeys === []) {
            return;
        }

        // `only()`, NU `getChanges()`/`getOriginal()` direct: `only()` trece prin
        // `getAttribute()`, deci aplică CAST-urile modelului (`decimal:2` etc.) — găsit prin
        // test: `getChanges()` întoarce valoarea BRUTĂ, exact cum a fost atribuită
        // (`update(['price' => 12.5])` rămâne float `12.5`, nu `"12.50"`), în timp ce
        // `getOriginal()` reflectă ce a întors PDO la ultima citire (deja `"10.00"`, format
        // Postgres) — un diff neschimbat ar arăta „10.00 → 12.5", inconsecvent, pe orice
        // coloană `decimal`/`array`. O instanță „veche" temporară, cu atributele originale,
        // aplică ACELEAȘI cast-uri pentru ambele părți ale diff-ului.
        $newValues = $model->only($changedKeys);
        $oldValues = (new ($model::class))->setRawAttributes($model->getOriginal())->only($changedKeys);

        [$old, $new] = ChangedAttributes::fromEloquentUpdate($oldValues, $newValues);

        // Doar `updated_at` (sau alte coloane tehnice) s-a atins — nimic demn de jurnal
        // (§17.1: „doar câmpurile modificate"). Un `touch()` fără modificare de business
        // nu are ce căuta în audit.
        if ($old === null && $new === null) {
            return;
        }

        // GDPR-02 — vezi docblock-ul clasei: contactul tocmai a tranziționat spre
        // anonimizat (`ContactErasure`, ramura cu deals/orders). `$old`/`$new` de mai sus
        // cară numele/emailul/telefonul REAL dinainte de anonimizare — exact ce persoana a
        // cerut să fie șters. Cheile (câmpurile modificate) rămân vizibile, doar valorile
        // devin placeholder, identic cu ce ar produce oricum retenția de 36 de luni.
        if ($this->isContactErasureTransition($model, $oldValues, $newValues)) {
            [$old, $new] = $this->maskErasureValues($old, $new);
        }

        $this->record($model, 'updated', $old, $new);
    }

    public function deleted(Model $model): void
    {
        $oldValues = ChangedAttributes::snapshot($model->getAttributes());

        // GDPR-02 — un `Contact` nu are altă cale de ștergere fizică decât
        // `App\Support\Contacts\ContactErasure` (ramura fără deals/orders): niciun alt
        // punct din aplicație nu apelează `Contact::delete()`/`->delete()` pe un contact
        // (`ContactController::destroy()` trece mereu prin `ContactErasure::erase()`).
        // Deci ORICE `deleted` pe `Contact` E, prin construcție, o cerere de ștergere
        // (Art. 17) — snapshot-ul complet (nume/email/telefon) nu are ce căuta în clar în
        // jurnal, la fel ca la ramura de anonimizare de mai sus.
        if ($model instanceof Contact) {
            [$oldValues] = $this->maskErasureValues($oldValues, null);
        }

        $this->record($model, 'deleted', $oldValues, null);
    }

    /**
     * Tranziția SPECIFICĂ de erasure: `Contact.anonymized_at` trece din `null` în setat.
     * Verificată pe `$oldValues`/`$newValues` deja calculate (doar cheile SCHIMBATE), nu
     * printr-un apel separat la `getOriginal()` — dacă `anonymized_at` n-a fost atins,
     * cheia pur și simplu lipsește din ambele, deci condiția e `false` pentru orice update
     * obișnuit (schimbare de telefon, e-mail etc. din UI).
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function isContactErasureTransition(Model $model, array $oldValues, array $newValues): bool
    {
        return $model instanceof Contact
            && ($oldValues['anonymized_at'] ?? null) === null
            && ($newValues['anonymized_at'] ?? null) !== null;
    }

    /**
     * Cheile se păstrează (se vede CE câmp s-a schimbat), doar valorile devin placeholder
     * — aceeași convenție ca `App\Support\Activity\ActivityLogAnonymizer`, a cărei
     * constantă o refolosește, ca rândul scris acum să fie indistingibil de unul mascat
     * ulterior de retenția lunară.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function maskErasureValues(?array $old, ?array $new): array
    {
        $mask = static fn (array $values): array => array_map(
            static fn () => ActivityLogAnonymizer::PLACEHOLDER,
            $values,
        );

        return [
            $old === null ? null : $mask($old),
            $new === null ? null : $mask($new),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function record(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $tenantId = TenantScope::currentTenantId();

        // Fără context de tenant, nu există unde să scrie rândul (RLS respinge oricum
        // INSERT-ul, `.ai/rules/tenancy.md`) — nu ar trebui să se întâmple pentru modelele
        // observate (toate trăiesc sub grupul `{workspace}`), dar un observer care ar
        // arunca aici ar transforma o lipsă de context într-un 500 pe o operație de
        // business complet validă. Mai sigur: jurnalul lipsește, restul cererii continuă.
        if ($tenantId === null) {
            return;
        }

        ModelWasRecorded::dispatch(
            $tenantId,
            Auth::id(),
            $action,
            $model::class,
            (string) $model->getKey(),
            $oldValues,
            $newValues,
            (string) request()?->ip(),
            (string) request()?->userAgent(),
        );
    }
}
