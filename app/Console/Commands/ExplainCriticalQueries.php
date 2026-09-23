<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DoD-ul specific al Fazei 1 (plan §7.7): `EXPLAIN ANALYZE` pe cele zece interogări din
 * tabelul de indexuri compuse, pe seed-ul complet, cu eșec automat dacă planul conține
 * `Seq Scan` pe o tabelă mare.
 *
 * De ce o comandă și nu „ne uităm la output": ADR-003 addendum spune că `tenant_id` trebuie
 * să fie coloana de LIDER a indexului, altfel RLS poate fi cu ordine de mărime mai lent.
 * Asta e ușor de greșit la a treia migrație dintr-o zi lungă și imposibil de observat pe un
 * seed mic — un criteriu citit cu ochiul e un criteriu care se pierde.
 *
 * Intrările de căutare globală (BR-SEARCH-01) NU cad sub regula de mai sus (ADR-018): `%`,
 * `similarity()` și `ILIKE` nu sunt LEAKPROOF, deci sub RLS PostgreSQL le evaluează DUPĂ
 * politica de tenant — niciun index nu le poate accelera, iar planificatorul poate alege
 * legitim Seq Scan pe `contacts` (tenantul vitrină are ~jumătate din tabelă). Regula pentru
 * ele e alta: Seq Scan e ACCEPTAT explicit, cu notă în output, dar timpul de execuție e
 * verificat contra unui buget (implicit 200 ms — pragul p95 din specs §20.1 pentru citiri
 * simple). Restul interogărilor păstrează regula strictă de index.
 */
class ExplainCriticalQueries extends Command
{
    protected $signature = 'db:explain-critical
        {--tenant= : Slug-ul tenantului de analizat (implicit: cel cu cele mai multe comenzi)}
        {--threshold=10000 : Numărul de rânduri de la care un Seq Scan devine eroare (regula de index)}
        {--search-budget-ms=200 : Bugetul de execuție (ms) pentru intrările de căutare sub RLS (ADR-018)}';

    protected $description = 'EXPLAIN ANALYZE pe interogările critice; index strict, buget de timp pe căutare (ADR-018).';

    /**
     * Prefixul de etichetă (§ convenția din `queries()`) care marchează o intrare drept
     * căutare globală — regula de buget (ADR-018), nu regula de index.
     */
    private const SEARCH_LABEL_PREFIX = 'search — ';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            $this->components->error('Niciun tenant în bază. Rulează întâi `php artisan demo:seed-volume`.');

            return self::FAILURE;
        }

        $threshold = (int) $this->option('threshold');
        $searchBudgetMs = (float) $this->option('search-budget-ms');
        $sizes = $this->tableSizes();

        $this->components->info(
            "Tenant: {$tenant->name} ({$tenant->slug}) · prag Seq Scan: {$threshold} rânduri · buget căutare: {$searchBudgetMs} ms (ADR-018)"
        );

        $indexFailures = [];
        $budgetFailures = [];

        foreach ($this->queries($tenant) as $label => $query) {
            [$sql, $bindings] = $query;

            $plan = TenantContext::run($tenant, fn () => DB::selectOne(
                'EXPLAIN (ANALYZE, FORMAT JSON) '.$sql,
                $bindings
            ));

            // `EXPLAIN (FORMAT JSON)` întoarce o singură coloană, al cărei nume diferă
            // între versiuni/localizări („QUERY PLAN"), deci se ia prima, nu după nume.
            $row = (array) $plan;
            $decoded = json_decode((string) reset($row), true);
            $root = $decoded[0]['Plan'] ?? [];
            $duration = $decoded[0]['Execution Time'] ?? 0.0;

            if (str_starts_with($label, self::SEARCH_LABEL_PREFIX)) {
                $this->reportSearchEntry($label, $root, $duration, $searchBudgetMs, $budgetFailures);

                continue;
            }

            $this->reportIndexedEntry($label, $root, $duration, $threshold, $sizes, $indexFailures);
        }

        if ($indexFailures !== [] || $budgetFailures !== []) {
            $this->newLine();

            if ($indexFailures !== []) {
                $this->components->error(sprintf(
                    '%d interogări critice fac Seq Scan pe tabele mari. Remediul e un index compus cu `tenant_id` pe prima poziție (§7.7), nu relaxarea pragului.',
                    count($indexFailures)
                ));
            }

            if ($budgetFailures !== []) {
                $this->components->error(sprintf(
                    '%d intrări de căutare depășesc bugetul de %s ms sub RLS (ADR-018). Seq Scan e acceptat pentru ele; timpul, nu.',
                    count($budgetFailures),
                    $searchBudgetMs
                ));
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Toate interogările critice respectă regula lor: index strict, sau buget de timp pentru căutare (ADR-018).');

        return self::SUCCESS;
    }

    /**
     * Regula de INDEX (§7.7): eșec dacă planul conține `Seq Scan` pe o tabelă la sau peste
     * `$threshold` rânduri. Neschimbată de ADR-018 — se aplică tuturor intrărilor, mai puțin
     * căutării globale.
     *
     * @param  array<string, mixed>  $root
     * @param  array<string, int>  $sizes
     * @param  array<string, list<string>>  $failures
     */
    private function reportIndexedEntry(string $label, array $root, float $duration, int $threshold, array $sizes, array &$failures): void
    {
        $offending = collect($this->sequentialScans($root))
            ->filter(fn (string $table) => ($sizes[$table] ?? 0) >= $threshold)
            ->unique()
            ->values();

        if ($offending->isNotEmpty()) {
            $failures[$label] = $offending->all();
            $this->components->twoColumnDetail(
                "<fg=red>✗</> {$label}",
                sprintf('%.1f ms · Seq Scan pe %s · regulă: index (§7.7)', $duration, $offending->implode(', '))
            );

            return;
        }

        $this->components->twoColumnDetail("<fg=green>✓</> {$label}", sprintf('%.1f ms · regulă: index (§7.7)', $duration));
    }

    /**
     * Regula de BUGET (ADR-018), pentru căutarea globală: Seq Scan e ACCEPTAT explicit (notă
     * vizibilă în output), pentru că sub RLS niciun index nu poate accelera `%`/`similarity()`/
     * `ILIKE` — nu sunt LEAKPROOF, deci sunt evaluate după politica de tenant. Ce contează în
     * schimb e timpul de execuție, contra `$budgetMs`.
     *
     * @param  array<string, mixed>  $root
     * @param  array<string, float>  $failures
     */
    private function reportSearchEntry(string $label, array $root, float $duration, float $budgetMs, array &$failures): void
    {
        $scannedTables = collect($this->sequentialScans($root))->unique()->values();
        $seqScanNote = $scannedTables->isNotEmpty()
            ? sprintf(' · Seq Scan acceptat pe %s — căutare sub RLS, ADR-018', $scannedTables->implode(', '))
            : '';

        if ($duration > $budgetMs) {
            $failures[$label] = $duration;
            $this->components->twoColumnDetail(
                "<fg=red>✗</> {$label}",
                sprintf('%.1f ms peste bugetul de %s ms%s · regulă: buget (ADR-018)', $duration, $budgetMs, $seqScanNote)
            );

            return;
        }

        $this->components->twoColumnDetail(
            "<fg=green>✓</> {$label}",
            sprintf('%.1f ms%s · regulă: buget %s ms (ADR-018)', $duration, $seqScanNote, $budgetMs)
        );
    }

    private function resolveTenant(): ?Tenant
    {
        if ($slug = $this->option('tenant')) {
            return Tenant::query()->where('slug', $slug)->first();
        }

        // Implicit, tenantul „vitrină": cel mai bogat în date e singurul pe care
        // măsurătoarea spune ceva (§21.1 — ~30.000 de comenzi la Marlin).
        //
        // Numărătoarea se face tenant cu tenant, în contextul fiecăruia: `orders` are RLS,
        // deci un `GROUP BY tenant_id` de deasupra tuturor ar întoarce zero peste tot — una
        // dintre acele interogări care „merg" până când cineva se uită la rezultat.
        return Tenant::query()->get()
            ->sortByDesc(fn (Tenant $tenant) => TenantContext::run($tenant, fn () => DB::table('orders')->count()))
            ->first();
    }

    /**
     * @return array<string, int>
     */
    private function tableSizes(): array
    {
        // `reltuples` e estimarea planificatorului — exact cifra pe care se ia decizia de
        // plan, deci cifra corectă de comparat cu pragul. Un COUNT(*) exact ar fi mai lent
        // și ar răspunde la altă întrebare.
        return collect(DB::select("
            select relname as table_name, greatest(reltuples, 0)::bigint as rows
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind = 'r'
        "))->mapWithKeys(fn ($row) => [$row->table_name => (int) $row->rows])->all();
    }

    /**
     * Cele zece interogări din tabelul §7.7, plus căutarea globală (BR-SEARCH-01, Faza 2)
     * — aceleași forme SQL ca `App\Services\Search\GlobalSearchService`, ca planul măsurat
     * aici să fie chiar cel pe care îl execută cererea reală, nu o aproximare.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>}>
     */
    private function queries(Tenant $tenant): array
    {
        return [
            'memberships — membri activi' => [
                'select * from memberships where status = ? order by created_at desc limit 50', ['active'],
            ],
            'accounts — listă alfabetică (FR-CRM-03)' => [
                'select * from accounts order by name limit 50', [],
            ],
            'accounts — „My accounts" (US-CRM-02)' => [
                'select * from accounts where owner_user_id = (select owner_user_id from accounts where owner_user_id is not null limit 1) order by name limit 50', [],
            ],
            'contacts — tab Activity pe cont' => [
                'select * from contacts where account_id = (select id from accounts limit 1) limit 50', [],
            ],
            'deals — coloană de kanban' => [
                'select * from deals where stage_id = (select id from stages limit 1) limit 50', [],
            ],
            'deal_stage_events — raport Deal Velocity (§16.3)' => [
                'select * from deal_stage_events where deal_id = (select id from deals limit 1) order by changed_at', [],
            ],
            'stock_movements — istoric per variantă (FR-STOCK-03)' => [
                'select * from stock_movements where variant_id = (select id from variants limit 1) order by created_at desc limit 50', [],
            ],
            // Vederea `Products/Index` (`ProductList::baseQuery()`): `variants_count` și
            // `low_stock_variants_count` (FR-STOCK-02, App\Support\Stock\LowStockRule) sunt
            // subinterogări CORELATE pe `variants.product_id = products.id`, per rând de
            // produs. Fără `variants(tenant_id, product_id)` (migrația
            // `2026_09_14_150000_add_product_id_index_to_variants_table`), ambele scanau
            // toate variantele tenantului per produs — măsurat, 65,6 ms pe pagina implicită
            // de 50 de produse Marlin, `Rows Removed by Filter: 735` per produs.
            'products — listă, cu numărul de variante low stock (FR-STOCK-02)' => [
                'select products.*,
                    (select count(*) from variants where variants.product_id = products.id) as variants_count,
                    (select count(*) from variants where variants.product_id = products.id
                        and variants.is_active = true and variants.low_stock_threshold is not null
                        and (select coalesce(sum(inventory_levels.on_hand - inventory_levels.reserved), 0)
                             from inventory_levels where inventory_levels.variant_id = variants.id) < variants.low_stock_threshold
                    ) as low_stock_variants_count
                    from products order by name asc, id asc limit 50', [],
            ],
            'orders — listă filtrată pe status (FR-ORD-02)' => [
                'select * from orders where status = ? order by created_at desc limit 50', ['confirmed'],
            ],
            // Vederea IMPLICITĂ a `OrderList` pentru Owner/Manager: `defaultSort()` =
            // `-created_at`, FĂRĂ filtru de status (`defaultFilters()` întoarce `[]` — doar
            // Agentul primește `owner=me`). Indexul `(tenant_id, status, created_at)` de mai
            // sus nu o servește: ordonează DUPĂ status înăuntrul tenantului, deci sortarea pe
            // `created_at` peste toate statusurile nu poate citi indexul în ordine. Migrația
            // `2026_09_14_120000_add_created_at_index_to_orders_table` adaugă
            // `(tenant_id, created_at)` exact pentru acest caz (plan §17, intrarea 1.24).
            'orders — vedere implicită, fără filtru de status (plan §17, 1.24)' => [
                'select * from orders order by created_at desc limit 50', [],
            ],
            // P2-005 (review general) — jumătatea `orders` a indicatorului „Unassigned"
            // din navigație (`App\Support\Members\UnassignedRecordsCounter`, FR-TEN-05),
            // calculată per cerere pentru orice Owner/Manager. Migrația
            // `2026_09_14_170000_add_owner_user_id_index_to_orders_table` adaugă
            // `(tenant_id, owner_user_id)`, simetric cu indexul deja existent pe `deals`.
            'orders — indicator Unassigned (FR-TEN-05, P2-005)' => [
                "select count(*) from orders where status in ('draft', 'confirmed', 'partially_fulfilled')
                    and owner_user_id not in (select user_id from memberships where status = 'active')", [],
            ],
            'invoices — raport „Overdue invoices"' => [
                'select * from invoices where status = ? order by due_date limit 50', ['overdue'],
            ],
            'activity_log — feed de dashboard' => [
                'select * from activity_log order by created_at desc limit 10', [],
            ],
            // PERF-05 (audit 2026-09-23) — `ActivityLogController::index()` linia 53:
            // un Agent (fără `activity_log.view`, doar `activity_log.view_own`) primește
            // necondiționat `where('user_id', ...)` peste `orderByDesc('created_at')
            // ->orderByDesc('id')`. Migrația
            // `2026_09_23_100000_add_user_id_index_to_activity_log_table` adaugă
            // `(tenant_id, user_id, created_at)` exact pentru acest caz — măsurat sub RLS,
            // ca `throughput_app`, pe seed-ul complet (Marlin, 108.166 rânduri, agent cu
            // 15.236 rânduri proprii): ÎNAINTE, `Bitmap Heap Scan` pe TOT tenantul
            // (`Rows Removed by Filter: 92930`), 49,2 ms; DUPĂ, `Index Scan Backward` pe
            // noul index, 0,5 ms.
            'activity_log — filtrat pe utilizator (Agent, PERF-05)' => [
                'select * from activity_log where user_id = (select user_id from activity_log order by user_id limit 1) order by created_at desc, id desc limit 50', [],
            ],

            // Căutare globală (FR-SEARCH-01/02, BR-SEARCH-01, ADR-018) — termen cu o greșeală
            // de tastare deliberată („fastners" în loc de „fasteners"), ca planul măsurat să
            // fie cel al cazului pe care `%`/`similarity()` există să-l rezolve, nu al unei
            // potriviri exacte pe care un `=` ar rezolva-o oricum. FĂRĂ index GIN (ADR-018):
            // eticheta nu mai spune „trigram" ca să nu sugereze unul — `pg_trgm` rămâne
            // extensia care oferă operatorul și funcția, nu un index folosibil sub RLS.
            'search — accounts (BR-SEARCH-01, ADR-018)' => [
                'select id, name, domain, status from accounts where (name % ? or name ilike ?) order by similarity(name, ?) desc limit 5',
                ['fastners', '%fastners%', 'fastners'],
            ],
            'search — contacts (nume complet, ADR-018)' => [
                "select id, first_name, last_name from contacts where ((first_name || ' ' || last_name) % ? or (first_name || ' ' || last_name) ilike ?) order by similarity(first_name || ' ' || last_name, ?) desc limit 5",
                ['jon smth', '%jon smth%', 'jon smth'],
            ],
            'search — deals (titlu, ADR-018)' => [
                'select id, title from deals where (title % ? or title ilike ?) order by similarity(title, ?) desc limit 5',
                ['anual suply', '%anual suply%', 'anual suply'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function sequentialScans(array $node): array
    {
        $found = [];

        if (($node['Node Type'] ?? null) === 'Seq Scan' && isset($node['Relation Name'])) {
            $found[] = $node['Relation Name'];
        }

        foreach ($node['Plans'] ?? [] as $child) {
            $found = array_merge($found, $this->sequentialScans($child));
        }

        return $found;
    }
}
