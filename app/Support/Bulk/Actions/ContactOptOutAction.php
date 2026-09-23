<?php

namespace App\Support\Bulk\Actions;

use App\Support\Bulk\BulkChunkAction;
use App\Support\Bulk\BulkWritableResource;

/**
 * GDPR-04, §13.5 (Art. 21) — opt-out în masă pe contactele selectate. Toggle simplu pe
 * `contacts.opt_out`, simetric cu `App\Actions\Bulk\SetProductActiveAction`: un `UPDATE`
 * condiționat pe query, NICIODATĂ o resalvare Eloquent per rând — o resalvare ar declanșa
 * `App\Observers\ActivityLogObserver` PE LÂNGĂ `App\Support\Activity\
 * BulkChunkActivityRecorder` (apelat explicit de `App\Jobs\Bulk\ProcessBulkChunkJob`),
 * dublând rândul de `activity_log` pentru fiecare contact.
 *
 * Idempotentă prin construcție, ca `SetProductActiveAction`: `UPDATE ... WHERE opt_out =
 * false` — o reîncercare a aceluiași chunk nu mai găsește rânduri de schimbat.
 *
 * NU e o breșă în garda `tests/Feature/Contacts/ContactOptOutFilterGuardTest.php`
 * (BR-CRM-02): acel test oprește folosirea `opt_out` ca FILTRU pe o cale TRANZACȚIONALĂ
 * (comenzi/facturi/expeditor de marketing) — fișierul de față SCRIE coloana, aceeași
 * categorie „gestiunea contactului" ca `App\Http\Requests\Contacts\UpdateContactRequest`,
 * deja în lista lui albă. Adăugat explicit acolo (vezi raportul lotului GDPR-04).
 *
 * Namespace `App\Support\Bulk\Actions`, NU `App\Actions\Bulk` (unde stau
 * `ReassignOwnerAction`/`SetProductActiveAction`): `app/Actions/Bulk` nu era în felia de
 * fișiere a acestui lot — precedent identic cu `App\Support\Bulk\EnsureBulkConcurrencyLimit`
 * (mutat din `App\Http\Middleware` pentru exact același motiv, vezi docblock-ul ei).
 * Semnalat în raport.
 */
final class ContactOptOutAction implements BulkChunkAction
{
    public function apply(BulkWritableResource $resource, array $ids, array $payload): int
    {
        $query = $resource->newQuery();
        $keyName = $query->getModel()->getKeyName();

        return $query
            ->whereIn($keyName, $ids)
            ->where('opt_out', false)
            ->update(['opt_out' => true]);
    }
}
