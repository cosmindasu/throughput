<?php

namespace App\Support\Bulk;

use App\Models\BulkOperation;
use App\Models\User;

/**
 * §22.5, rândul „Operații în masă (per user): 3 operații concurente active".
 *
 * ACEEAȘI limită pentru TOATE rolurile, inclusiv pe exportul Viewer-ului — BR-BULK-03 și
 * nota ³ de la §7.4 sunt explicite: exportul e o CITIRE a rândurilor deja vizibile pe ecran,
 * permisă Viewer-ului; contenția vine din limita asta, nu dintr-un refuz de rol. Deci aici
 * NU există nicio ramură pe rol, iar asta e intenția, nu o omisiune.
 *
 * „Activ" = `pending` sau `running` — stările neterminale ale lui `bulk_operations`. Rândurile
 * blocate nu blochează la nesfârșit: operațiile de SCRIERE rămase fără `batch_id` sunt închise
 * de `App\Jobs\System\FailStuckBulkOperationsJob` (15 min), iar exporturile au `$tries` finit
 * plus `failed()` în `App\Jobs\Exports\ExportListJob`. Spre deosebire de `ImportConcurrencyGuard`
 * (unde un import „uploaded" abandonat blochează tenantul fără plasă), aici ambele familii au
 * deja ieșire automată — de-asta pragul n-are nevoie de un sweeper propriu.
 *
 * DOUĂ diferențe deliberate față de `App\Support\Imports\ImportConcurrencyGuard`, ambele
 * pentru că invarianta e alta:
 *
 *  1. **Fără `lock('for no key update')` pe rândul de tenant.** Acolo, două upload-uri
 *     simultane chiar produceau o stare greșită (două importuri care își detectează reciproc
 *     duplicatele). Aici, pragul e o măsură de CONTENȚIE: o a patra operație strecurată prin
 *     fereastra dintre COUNT-ul uneia și INSERT-ul celeilalte nu produce date greșite, doar
 *     un job în plus pe coada `bulk` — iar blocarea rândului de tenant la FIECARE export ar
 *     serializa, în schimb, toate operațiile în masă ale workspace-ului, inclusiv ale altor
 *     utilizatori. Costul ar depăși cu mult ce previne.
 *  2. **Numărătoarea e per UTILIZATOR**, nu per tenant (`user_id`), conform §22.5.
 *
 * Domeniul numărătorii e, mecanic, „utilizatorul ÎN workspace-ul curent": `bulk_operations`
 * are `tenant_id` și RLS (ADR-003), deci o interogare nu poate vedea rândurile aceluiași om
 * din alt workspace — și nici nu trebuie să poată. E o abatere CONȘTIENTĂ de la litera lui
 * §22.5 („per user"), semnalată în raportul lotului: în demo-ul public conturile sunt
 * partajate (§4.2), deci limita lucrează exact acolo unde apare contenția reală.
 */
final class BulkConcurrencyGuard
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        BulkOperation::STATUS_PENDING,
        BulkOperation::STATUS_RUNNING,
    ];

    /**
     * §22.5 — 3. Cheia de config nu există încă în `config/throughput.php` (fișier de
     * integrare, neatins de acest lot; blocul exact e în raport), de aceea implicitul stă
     * aici. Odată adăugată cheia, ea câștigă — inclusiv în teste, prin `config([...])`.
     */
    public static function limit(): int
    {
        return max(1, (int) (config('throughput.limits.bulk_concurrent_per_user') ?? 3));
    }

    public static function activeCountFor(User $user): int
    {
        return BulkOperation::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->count();
    }

    public static function hasReachedLimit(User $user): bool
    {
        return self::activeCountFor($user) >= self::limit();
    }

    /**
     * Mesajul spune CE să facă persoana, nu doar că a fost refuzată — un export refuzat fără
     * explicație se citește ca „aplicație stricată" (aceeași regulă ca la butoanele din `can`).
     *
     * ADR-022, Lot I18N Val 2 — text mutat în `rules.bulk.concurrency_limit`. Are DOI
     * apelanți, nu unul: `App\Support\Bulk\EnsureBulkConcurrencyLimit` (o
     * `ValidationException`, domeniul acestui lot) ȘI `App\Support\Exports\ListExport`
     * (un mesaj flash `back()->with('error', ...)`, alt domeniu al lotului I18N). Ambii
     * citesc ACELAȘI text — o singură sursă tradusă aici, corectă pentru amândoi, în loc de
     * două traduceri care ar putea diverge.
     */
    public static function refusal(): string
    {
        return trans_choice('rules.bulk.concurrency_limit', self::limit());
    }
}
