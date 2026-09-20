<?php

namespace App\Support\Activity;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §13.2 pct. 3/5, §13.3 (US-BULK-01) — instrumentarea EXPLICITĂ a operațiilor în masă.
 *
 * `App\Actions\Bulk\ReassignOwnerAction` (și orice alt `App\Support\Bulk\BulkChunkAction`)
 * face un `->update()` ÎN MASĂ, pe care observers Eloquent (`App\Observers\
 * ActivityLogObserver`) NU-l văd niciodată — evenimentele `updated`/`deleted` ale
 * Eloquent nu se declanșează pentru un `Builder::update()`/`delete()` de query, doar
 * pentru salvarea unei instanțe. De aici acest recorder, apelat explicit de
 * `App\Jobs\Bulk\ProcessBulkChunkJob`, cu instantanee citite ÎNAINTE și DUPĂ `apply()`.
 *
 * Un rând de `activity_log` PER ÎNREGISTRARE efectiv modificată (nu una per chunk): tabul
 * „History" al unei entități trebuie să arate modificarea făcută în masă exact ca pe una
 * făcută manual, iar linkul „activity_log filtrat pe această operație" din US-BULK-01 are
 * nevoie de un rând per entitate ca să însemne ceva. INSERĂRI PE LOTURI (`DB::table()->
 * insert()`, un singur statement per chunk de ~500-1.000 rânduri, NICIODATĂ un `create()`
 * Eloquent per rând) — un chunk de 1.842 conturi ar însemna altfel 1.842 round-trip-uri.
 *
 * `DB::table()`, nu `App\Models\ActivityLog::insert()`: identic cu motivul din
 * `App\Jobs\System\PruneSentEmailsJob` — inserarea în masă ocolește oricum evenimentele
 * Eloquent (deci n-ar lovi garda `App\Concerns\AppendOnly`, care oprește doar
 * `updating`/`deleting`), dar `DB::table()` scutește de grija cast-urilor (`old_values`/
 * `new_values` sunt `jsonb` — un `insert()` Eloquent în masă NU trece prin `casts()`, deci
 * ar scrie array-uri PHP brute acolo unde driverul așteaptă text JSON) — `json_encode()`
 * explicit, ca în `Database\Seeders\Support\ActivityLogRecorder`.
 *
 * Idempotență: NU proprie — moștenită de la apelant. `ProcessBulkChunkJob` cheamă acest
 * recorder DOAR după ce `insertOrIgnore()` pe `bulk_operation_chunks` a confirmat că
 * chunk-ul nu fusese aplicat încă, ÎN ACEEAȘI tranzacție ca `apply()` — o reîncercare a
 * ACELUIAȘI chunk se oprește înainte să ajungă aici, deci acest recorder nu se execută
 * niciodată de două ori pentru același chunk.
 */
final class BulkChunkActivityRecorder
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  Collection<int|string, Model>  $before  cheiat pe cheia primară (`keyBy()`)
     * @param  Collection<int|string, Model>  $after  cheiat pe cheia primară, DUPĂ `apply()`
     */
    public function record(
        string $modelClass,
        string $bulkOperationId,
        ?string $actorUserId,
        string $ipAddress,
        string $userAgent,
        Collection $before,
        Collection $after,
    ): void {
        $tenantId = TenantScope::requireCurrentTenantId();
        $now = now();
        $rows = [];

        foreach ($after as $key => $afterModel) {
            $beforeModel = $before->get($key);

            // N-ar trebui să lipsească (ambele interogări pornesc de la ACELEAȘI id-uri),
            // dar un rând șters concurent între cele două citiri (BR-BULK-01: un job
            // individual poate eșua, restul continuă) n-are ce „înainte/după" să compare.
            if ($beforeModel === null) {
                continue;
            }

            [$old, $new] = ChangedAttributes::diff($beforeModel->getAttributes(), $afterModel->getAttributes());

            // Rândul era deja pe valoarea țintă (ex: owner deja `John Smith`) — niciun
            // efect real, deci niciun rând de jurnal. `apply()` însuși exclude majoritatea
            // acestor rânduri prin `WHERE`-ul lui condiționat, dar diff-ul e sursa de
            // adevăr aici, independent de cum a filtrat acțiunea.
            if ($old === null && $new === null) {
                continue;
            }

            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'tenant_id' => $tenantId,
                'user_id' => $actorUserId,
                'action' => 'bulk_action',
                'auditable_type' => $modelClass,
                'auditable_id' => (string) $key,
                'bulk_operation_id' => $bulkOperationId,
                'old_values' => $old !== null ? json_encode($old) : null,
                'new_values' => $new !== null ? json_encode($new) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table('activity_log')->insert($rows);
    }
}
