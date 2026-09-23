<?php

namespace App\Support\Activity;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Nucleul SQL de mascare a `activity_log`, extras din `App\Jobs\System\
 * AnonymizeActivityLogJob` (GDPR-02, audit 2026-09-23, `docs/reviews/2026-09-23_audit/
 * 08-gdpr.md`) ca să poată fi refolosit din DOUĂ locuri cu criterii de selecție diferite:
 *
 *  - `AnonymizeActivityLogJob` — retenția LUNARĂ, pe `created_at <= cutoff` +
 *    `auditable_type` personal (Contact/User), specs.md §17.2;
 *  - `App\Support\Contacts\ContactErasure` — la MOMENTUL ștergerii/anonimizării unui
 *    contact (Art. 17), pe `auditable_type = Contact::class AND auditable_id = $id`,
 *    indiferent de vechime.
 *
 * Apelantul construiește `$query` (deja filtrat, deja sub contextul de tenant curent —
 * RLS se aplică normal, fiindcă e tot `DB::table()`, nicio conexiune specială). Clasa de
 * aici nu știe și nu trebuie să știe CE selectează apelantul — doar CUM mască rândurile
 * selectate.
 *
 * **De ce `UPDATE` prin `DB::table()`/SQL brut, nu `App\Models\ActivityLog::query()->
 * update()`**: un `Builder::update()` în masă nu declanșează evenimente Eloquent (deci
 * n-ar lovi oricum garda `App\Concerns\AppendOnly`, care oprește doar `updating`/
 * `deleting` PER INSTANȚĂ), dar SQL-ul brut evită orice ambiguitate legată de global
 * scope-ul de tenant și de cast-ul `array` pe coloane `jsonb` (un `UPDATE` scrie JSON
 * direct, calculat de PostgreSQL însuși).
 *
 * **De ce transformarea rulează ÎN SQL (`jsonb_object_agg`/`jsonb_each`), nu în PHP**: o
 * transformare per cheie JSON, per rând, în PHP ar cere fie citirea integrală a rândurilor
 * (cost de memorie pe un tenant/contact cu istoric mare), fie N interogări individuale. Un
 * singur `UPDATE ... FROM (SELECT ...)` per chunk de id-uri face ambele citiri și scrierea
 * într-un singur round-trip. Cheile JSON (numele câmpurilor modificate) se PĂSTREAZĂ —
 * doar valorile devin placeholder-ul de mai jos: se vede CE câmp s-a schimbat, nu CE
 * valoare a avut.
 *
 * Idempotent prin CONVERGENȚĂ, nu prin marcaj: re-aplicarea transformării unui rând deja
 * mascat produce exact același rezultat, deci o rulare care se suprapune cu alta (retry,
 * job reluat) nu dublează nimic. `IS DISTINCT FROM` din `UPDATE` e doar o optimizare (sare
 * peste rândurile deja convergente, deci nu le numără în valoarea întoarsă), nu condiția
 * de corectitudine.
 */
final class ActivityLogAnonymizer
{
    public const PLACEHOLDER = '[anonymized]';

    private const CHUNK_SIZE = 500;

    /**
     * Maschează `old_values`/`new_values` pe toate rândurile selectate de `$query`,
     * paginat cu `chunkById` pe `id` (ULID, sortabil), 500 pe pagină — același motiv ca în
     * restul joburilor de retenție: memorie constantă, indiferent de câte rânduri
     * potrivesc.
     *
     * @return int numărul de rânduri EFECTIV atinse (schimbate) — rândurile deja
     *             convergente (mascate la o rulare anterioară) nu se numără.
     */
    public static function anonymize(Builder $query): int
    {
        $affected = 0;

        $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use (&$affected): void {
            $affected += self::anonymizeChunk($rows->pluck('id')->all());
        }, 'id');

        return $affected;
    }

    /** @param  list<string>  $ids */
    private static function anonymizeChunk(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // `jsonb_each` cere un OBJECT jsonb — `old_values`/`new_values` sunt mereu obiecte
        // „nume câmp => valoare" (niciodată liste), per convenția de scriere din
        // `App\Support\Activity\ChangedAttributes`, deci fără riscul erorii Postgres
        // „cannot call jsonb_each on a non-object".
        return DB::affectingStatement(
            <<<SQL
                UPDATE activity_log a
                SET old_values = t.new_old,
                    new_values = t.new_new
                FROM (
                    SELECT
                        id,
                        CASE WHEN old_values IS NULL THEN NULL
                             ELSE (SELECT jsonb_object_agg(e.key, to_jsonb(?::text)) FROM jsonb_each(old_values) AS e)
                        END AS new_old,
                        CASE WHEN new_values IS NULL THEN NULL
                             ELSE (SELECT jsonb_object_agg(e.key, to_jsonb(?::text)) FROM jsonb_each(new_values) AS e)
                        END AS new_new
                    FROM activity_log
                    WHERE id IN ({$placeholders})
                ) t
                WHERE a.id = t.id
                  AND (a.old_values IS DISTINCT FROM t.new_old OR a.new_values IS DISTINCT FROM t.new_new)
                SQL,
            [self::PLACEHOLDER, self::PLACEHOLDER, ...$ids],
        );
    }
}
