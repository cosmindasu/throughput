<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-TEN-02 — `tenant_id` trebuie să fie mereu coloana de lider în indexurile compuse
 * ale tabelelor tenant-scoped. Indexurile există deja (adăugate treptat, Faza 1-3); acest
 * test le verifică direct în `pg_index`/`pg_catalog` al bazei de test migrate, nu doar le
 * citește din fișierul de migrație — o migrație care scrie `(status, tenant_id)` în loc de
 * `(tenant_id, status)` ar trece code review-ul la fel de ușor ca varianta corectă, și
 * `\d tabela` n-ar semnala nimic.
 *
 * Motivul structural, nu doar stilistic (`.ai/rules/tenancy.md`, „Politicile RLS: cast pe
 * setare, nu pe coloană"): politica RLS filtrează pe `tenant_id = current_setting(...)`
 * pentru FIECARE interogare pe tabela respectivă, indiferent de alte filtre din query.
 * Un index compus care NU are `tenant_id` pe prima poziție nu poate servi acest predicat —
 * planificatorul degradează tăcut la Seq Scan, exact capcana deja măsurată în proiect
 * (`orders`, 50.000 de rânduri, §7.7 din plan-implementare.md).
 *
 * Lista de tabele se derivă din `information_schema.columns` (schema reală, migrată), NU
 * din §7.7 din plan-implementare.md — acela e o „recapitulare", nu sursă de adevăr, și nu
 * listează toate cele 35 de tabele tenant-scoped din schema actuală (verificat 2026-09-22).
 */
class CompositeIndexLeadingColumnTest extends TestCase
{
    public function test_tenant_id_leads_every_composite_index_on_tenant_scoped_tables(): void
    {
        $tenantScopedTables = $this->tenantScopedTables();

        // Aceeași gardă anti-„vacuous truth" ca în IsolationTest: dacă schema s-ar rupe și
        // interogarea de mai jos ar întoarce un set gol sau trunchiat, bucla de mai jos ar
        // trece verde fără să verifice nimic.
        $this->assertGreaterThan(25, count($tenantScopedTables), 'Schema pare incompletă — verifică migrațiile.');

        $indexesByTable = $this->compositeIndexesByTable($tenantScopedTables);

        $wrongPosition = [];
        $missingLeadingIndex = [];

        foreach ($tenantScopedTables as $table) {
            $hasLeadingCompositeIndex = false;

            foreach ($indexesByTable[$table] ?? [] as $index) {
                $position = array_search('tenant_id', $index->columns, true);

                if ($position === 0) {
                    $hasLeadingCompositeIndex = true;
                } elseif ($position !== false) {
                    $wrongPosition[] = sprintf(
                        '%s.%s: tenant_id pe poziția %d din %d (%s)',
                        $table,
                        $index->index_name,
                        $position,
                        count($index->columns),
                        implode(',', $index->columns),
                    );
                }
            }

            if (! $hasLeadingCompositeIndex && ! in_array($table, $this->tablesWithoutACompoundAccessPattern(), true)) {
                $missingLeadingIndex[] = $table;
            }
        }

        $this->assertSame(
            [],
            $wrongPosition,
            "Index compus cu tenant_id NU pe prima poziție — planificatorul RLS nu-l poate folosi (FR-TEN-02):\n"
            .implode("\n", $wrongPosition),
        );

        $this->assertSame(
            [],
            $missingLeadingIndex,
            'Tabelă tenant-scoped fără niciun index compus cu tenant_id pe prima poziție, și fără '
            .'excludere motivată în tablesWithoutACompoundAccessPattern(): '.implode(', ', $missingLeadingIndex),
        );
    }

    /**
     * Excludere EXPLICITĂ de la cerința de EXISTENȚĂ (nu de la interdicția de poziție greșită,
     * care rămâne universală, mai sus) — fiecare tabelă verificată, nu presupusă: un grep pe
     * `app/` (2026-09-22) nu găsește nicio interogare care filtrează aceste tabele pe o A DOUA
     * coloană pe lângă `tenant_id`.
     *
     * - `locations`, `pipelines`: tabele mici de referință (câteva rânduri per tenant — un
     *   pipeline implicit, câteva depozite); interogate cu `where('is_default', ...)`,
     *   `oldest('created_at')` sau `findOrFail()`, niciodată cu un al doilea filtru compus cu
     *   `tenant_id`. Sub RLS, `tenant_id = current_setting(...)` singur pe un Seq Scan de
     *   câteva rânduri nu are ce plan mai bun să capete dintr-un index.
     * - `shipment_lines`: tabelă-copil, accesată exclusiv prin relația părinte
     *   (`$shipment->shipmentLines()`, `$orderLine->shipmentLines()`), niciodată printr-o
     *   interogare directă filtrată pe tenant_id + altă coloană.
     * - `bulk_operation_chunks`: SCRIS EXCLUSIV — singura interogare din tot `app/` e
     *   `insertOrIgnore()` din `ProcessBulkChunkJob::handle()` (marcaj de idempotență per
     *   chunk, `.ai/rules/tenancy.md` §„Rânduri create la cerere"); niciun `where()`/`get()`
     *   pe acest model NICĂIERI în cod. Indexul unic existent, `(bulk_operation_id, chunk)`,
     *   servește exact acel `insertOrIgnore` (bulk_operation_id e deja un ULID unic global,
     *   deci n-are nevoie de tenant_id ca prefix) — nu există niciun tipar de citire de servit.
     *
     * O interogare nouă cu un al doilea filtru pe oricare din aceste tabele ar trebui să scoată
     * tabela din listă și să adauge indexul corespunzător — nu să extindă lista tăcut.
     *
     * @return list<string>
     */
    private function tablesWithoutACompoundAccessPattern(): array
    {
        return ['locations', 'pipelines', 'shipment_lines', 'bulk_operation_chunks'];
    }

    /**
     * @return list<string>
     */
    private function tenantScopedTables(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'tenant_id')
            ->pluck('table_name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Indexurile compuse (2+ coloane de cheie) ale tabelelor date, citite direct din
     * `pg_index`, grupate pe tabelă. `indnkeyatts` exclude deliberat coloanele `INCLUDE`
     * (nu fac parte din cheia de sortare, deci nu contează pentru poziția lui `tenant_id`).
     * Coloanele vin prin `pg_get_indexdef(indexrelid, poziție, true)`, nu prin
     * `indkey`/`pg_attribute` direct, ca să rămână corecte și pentru indexuri pe expresie
     * (`attnum = 0`, fără intrare în `pg_attribute`) — nu există în acest schema, dar
     * interogarea nu trebuie să presupună asta tăcut.
     *
     * @param  list<string>  $tables
     * @return array<string, list<object{index_name: string, columns: list<string>}>>
     */
    private function compositeIndexesByTable(array $tables): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $rows = DB::select(
            "select t.relname as table_name, i.relname as index_name,
                    (select array_to_json(array_agg(pg_get_indexdef(ix.indexrelid, gs, true) order by gs))
                       from generate_series(1, ix.indnkeyatts) as gs) as columns
             from pg_index ix
             join pg_class t on t.oid = ix.indrelid
             join pg_class i on i.oid = ix.indexrelid
             join pg_namespace n on n.oid = t.relnamespace
             where n.nspname = 'public' and t.relname in ({$placeholders})",
            $tables,
        );

        $byTable = [];

        foreach ($rows as $row) {
            // pg_get_indexdef(..., true) pune ghilimele în jurul identificatorilor rezervați
            // (ex. `"position"` pe stages) — le scoatem, ne interesează numele coloanei, nu
            // reprezentarea ei SQL.
            $columns = array_map(
                fn (string $column): string => trim($column, '"'),
                json_decode($row->columns, true) ?? [],
            );

            if (count($columns) < 2) {
                continue; // nu e index compus — nu are ce poziție greșită să aibă.
            }

            $byTable[$row->table_name][] = (object) [
                'index_name' => $row->index_name,
                'columns' => $columns,
            ];
        }

        return $byTable;
    }
}
