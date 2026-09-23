<?php

namespace App\Support\Bulk\Actions;

use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Contacts\ContactErasure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * GDPR-04, §13.5 (Art. 17, RTBF) — ștergere/anonimizare în masă a contactelor selectate.
 * Spre deosebire de restul acțiunilor de chunk (`ReassignOwnerAction`, `SetProductActiveAction`
 * etc.), NU e un `UPDATE`/`DELETE` de query pe lot întreg: decizia „anonimizare vs. ștergere
 * fizică" se ia PER CONTACT (are deals/orders sau nu — BR-CRM-01/02), deci fiecare id trece
 * individual prin `App\Support\Contacts\ContactErasure::erase()`, sursa unică a acestei
 * decizii — semnătura ei NU se atinge aici (un alt lot îi modifică interiorul, în paralel,
 * pentru propagarea în `activity_log`, GDPR-02).
 *
 * Consecință acceptată: contactele fizic șterse dispar din interogarea „after" a lui
 * `App\Jobs\Bulk\ProcessBulkChunkJob` (`Contact::query()` nu le mai găsește), iar cele
 * anonimizate dispar la fel, ascunse de `App\Models\Scopes\NotAnonymizedContactScope` —
 * deci `App\Support\Activity\BulkChunkActivityRecorder` (diff „before"/„after") NU scrie
 * niciun rând `bulk_action` pentru acțiunea asta. Nu e o gaură de audit: `ContactErasure`
 * face `->save()` (anonimizare) sau `->delete()` (ștergere fizică) PE INSTANȚĂ, deci
 * `App\Observers\ActivityLogObserver` scrie deja rândul normal `updated`/`deleted` —
 * IDENTIC cu ce s-ar întâmpla la o ștergere individuală prin `ContactController::destroy()`.
 * O dublă instrumentare (recorder generic + observer) ar fi fost regresia de evitat, nu
 * absența uneia dintre ele.
 *
 * Fără try/catch generic în jurul lui `erase()` — vezi docblock-ul `ContactErasure`:
 * excepțiile SQL prinse fără rollback la savepoint lasă tranzacția jobului în `25P02`.
 * Singura excepție prinsă aici, `ModelNotFoundException`, NU e o excepție SQL: e aruncată
 * de Eloquent DUPĂ un `SELECT` reușit care n-a găsit rândul (concurență — un contact
 * șters/anonimizat între planificarea chunk-ului și execuția lui, BR-BULK-01), deci
 * prinderea ei nu lasă nimic de rollback-uit.
 *
 * Idempotentă la nivel de CHUNK prin marcajul din `ProcessBulkChunkJob`
 * (`bulk_operation_chunks`) — o reîncercare a ACELUIAȘI chunk deja aplicat (tranzacție
 * comisă) nu mai ajunge aici. O reîncercare a unui chunk NEaplicat (crash înainte de
 * commit) găsește contactele neatinse, fiindcă nimic n-a fost comis, și le procesează
 * normal.
 *
 * Namespace `App\Support\Bulk\Actions`, NU `App\Actions\Bulk` — vezi docblock-ul
 * `ContactOptOutAction` din același director pentru motiv.
 */
final class ContactBulkDeleteAction implements BulkChunkAction
{
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $affected = 0;

        foreach ($ids as $id) {
            try {
                ContactErasure::erase($id);
                $affected++;
            } catch (ModelNotFoundException) {
                // Contactul a dispărut concurent (anonimizat/șters între planificarea
                // chunk-ului și execuția lui) — BR-BULK-01: continuă pe restul rândurilor.
                continue;
            }
        }

        return $affected;
    }
}
