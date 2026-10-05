<?php

namespace App\Support\Activity;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cine are voie să vadă NUMELE și VALORILE înregistrării dintr-un rând de jurnal.
 *
 * Jurnalul îngustează pe ACTOR („arată-mi ce am făcut eu"), nu pe vizibilitatea
 * înregistrării. Pentru aproape toate tipurile auditate asta nu înseamnă nimic: un Agent
 * poate deschide orice cont, contact, afacere, comandă sau produs din tenant — listele lui
 * pornesc filtrate pe „ale mele", dar acceptă „toate". `Invoice` e SINGURA excepție:
 * `InvoicePolicy::view()` o restrânge necondiționat la comenzile al căror owner e el.
 * (Verificat policy cu policy: `isWithinOwnRecords` apare și la Account, Contact, Deal,
 * Order, Shipment — dar niciodată pe `view`. Viewer-ul NU e atins: `restrictedToOwnRecords`
 * e adevărat doar pentru Agent, iar Viewer-ul are `invoices.view` pe tot tenantul.)
 *
 * Rezultă o fereastră îngustă dar reală: un rând scris de utilizator CÂND avea dreptul
 * rămâne al lui prin `user_id`, deși între timp a pierdut accesul la factură — un Manager
 * retrogradat în Agent, sau un Agent căruia i s-a reatribuit comanda.
 *
 * **Se maschează NUMELE și VALORILE, nu se ascunde rândul.** Alternativa era să scoatem
 * rândul din jurnalul propriu al utilizatorului; ar fi fost consistentă cu „dacă nu poți
 * vedea factura, nu vezi nimic despre ea", dar un jurnal de audit care omite ce ai făcut TU
 * spune o neadevărată despre propriul istoric. Rândul rămâne — „Created Invoice", cu dată și
 * actor — fără să numească factura și fără link.
 *
 * `old_values`/`new_values` intră în mască DEOPOTRIVĂ, și ăsta e lucrul ușor de ratat:
 * `ActivityLogObserver::created()` scrie instantaneul COMPLET al atributelor
 * (`ChangedAttributes::snapshot()` taie doar `id`, `tenant_id` și timestamp-urile), deci un
 * rând `created` scris pe calea reală poartă `invoice_number`, `total` și `balance_due`.
 * Mascarea doar a lui `subjectName` ar fi fost cosmetică: numărul pleca oricum în props-urile
 * Inertia, un nivel mai jos. În baza de dezvoltare nu se vede — seed-ul scrie rândurile
 * `created` cu valori NULL — deci singura plasă e testul.
 */
final class ActivityVisibility
{
    /**
     * Relațiile necesare DECIZIEI, încărcate odată cu `auditable`.
     *
     * Fără asta, verificarea de mai jos ar atinge `$invoice->order` per rând — adică o
     * interogare per factură pe o pagină paginată, sau o excepție, fiindcă proiectul
     * interzice lazy loading. `morphWith` le aduce grupat, pe tip.
     *
     * NU e condiționat pe rol, deși pentru Owner/Manager/Viewer relația nu e niciodată
     * citită. Măsurat pe baza de dezvoltare (185.806 rânduri de jurnal, 3 tenanți): costul e
     * O(TIPURI), nu O(facturi) — o singură interogare pe pagină, oricâte facturi are, `Index
     * Scan using orders_pkey` cu două coloane (`width=54`), 0,040 ms warm p50 pentru o pagină
     * tipică de 11 facturi, 0,107 ms pentru 50. Dominat de round-trip-ul PDO, nu de Postgres.
     * Alternativa — să primească `?User` și să sară peste `morphWith` pentru rolurile care nu
     * citesc relația — ar fi scutit acea interogare, în schimbul unei ramuri „cade închis"
     * care se comportă diferit în funcție de cine se uită. 0,04 ms e mai ieftin decât
     * asimetria aceea.
     */
    public static function eagerLoad(MorphTo $morph): void
    {
        $morph->morphWith([Invoice::class => ['order:id,owner_user_id']]);
    }

    /**
     * Poate utilizatorul să vadă numele și valorile înregistrării atinse de acest rând?
     *
     * Decizia pornește de la `auditable_type` al RÂNDULUI, nu de la modelul încărcat: un rând
     * de ȘTERGERE n-are model (`auditable` iese `null`), dar `old_values` îi păstrează
     * numărul, iar `ActivityNarrative::deletedSubjectName()` îl citește de acolo. Pe modelul
     * încărcat, un `! $auditable instanceof Invoice` ar fi răspuns „da" exact atunci. Azi
     * nicio cale din aplicație nu șterge facturi (se folosește `void`), deci e o fereastră
     * închisă înainte să se deschidă — dar e închisă în locul unde contează, nu prin absența
     * unei funcționalități.
     */
    public static function mayNameSubject(ActivityLog $entry, ?User $user): bool
    {
        if ($entry->auditable_type !== Invoice::class) {
            return true;
        }

        // Fără utilizator nu există decizie de vizibilitate, deci nici un „da". Rutele sunt
        // toate sub `auth`, deci azi e inaccesibil — dar o resursă rezolvată în afara unei
        // cereri (un test, un job viitor) ar fi primit numărul.
        if ($user === null) {
            return false;
        }

        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        $invoice = $entry->relationLoaded('auditable') ? $entry->auditable : null;

        // Relația neîncărcată înseamnă că apelantul n-a trecut prin `eagerLoad()`; un model
        // absent înseamnă o factură ștearsă. În ambele, dreptul nu se poate DOVEDI. Nu se
        // ghicește și nu se interoghează: se maschează. O decizie de vizibilitate care cade
        // „deschis" când îi lipsește contextul n-ar fi o decizie.
        if (! $invoice instanceof Invoice || ! $invoice->relationLoaded('order')) {
            return false;
        }

        return $invoice->order?->owner_user_id === $user->getKey();
    }
}
