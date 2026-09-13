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
 */
class ExplainCriticalQueries extends Command
{
    protected $signature = 'db:explain-critical
        {--tenant= : Slug-ul tenantului de analizat (implicit: cel cu cele mai multe comenzi)}
        {--threshold=10000 : Numărul de rânduri de la care un Seq Scan devine eroare}';

    protected $description = 'EXPLAIN ANALYZE pe interogările critice; eșuează la Seq Scan pe tabele mari.';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        if ($tenant === null) {
            $this->components->error('Niciun tenant în bază. Rulează întâi `php artisan demo:seed-volume`.');

            return self::FAILURE;
        }

        $threshold = (int) $this->option('threshold');
        $sizes = $this->tableSizes();

        $this->components->info("Tenant: {$tenant->name} ({$tenant->slug}) · prag Seq Scan: {$threshold} rânduri");

        $failures = [];

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

            $offending = collect($this->sequentialScans($root))
                ->filter(fn (string $table) => ($sizes[$table] ?? 0) >= $threshold)
                ->unique()
                ->values();

            if ($offending->isNotEmpty()) {
                $failures[$label] = $offending->all();
                $this->components->twoColumnDetail(
                    "<fg=red>✗</> {$label}",
                    sprintf('%.1f ms · Seq Scan pe %s', $duration, $offending->implode(', '))
                );

                continue;
            }

            $this->components->twoColumnDetail("<fg=green>✓</> {$label}", sprintf('%.1f ms', $duration));
        }

        if ($failures !== []) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d interogări critice fac Seq Scan pe tabele mari. Remediul e un index compus cu `tenant_id` pe prima poziție (§7.7), nu relaxarea pragului.',
                count($failures)
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Toate interogările critice folosesc indexuri.');

        return self::SUCCESS;
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
            'orders — listă filtrată pe status (FR-ORD-02)' => [
                'select * from orders where status = ? order by created_at desc limit 50', ['confirmed'],
            ],
            'invoices — raport „Overdue invoices"' => [
                'select * from invoices where status = ? order by due_date limit 50', ['overdue'],
            ],
            'activity_log — feed de dashboard' => [
                'select * from activity_log order by created_at desc limit 10', [],
            ],

            // Căutare globală (FR-SEARCH-01/02, BR-SEARCH-01) — termen cu o greșeală de
            // tastare deliberată („fastners" în loc de „fasteners"), ca planul măsurat să
            // fie cel al cazului pe care indexul trigram există să-l rezolve, nu al unei
            // potriviri exacte pe care orice index ar rezolva-o oricum.
            'search — accounts (trigram, BR-SEARCH-01)' => [
                'select id, name, domain, status from accounts where (name % ? or name ilike ?) order by similarity(name, ?) desc limit 5',
                ['fastners', '%fastners%', 'fastners'],
            ],
            'search — contacts (trigram, nume complet)' => [
                "select id, first_name, last_name from contacts where ((first_name || ' ' || last_name) % ? or (first_name || ' ' || last_name) ilike ?) order by similarity(first_name || ' ' || last_name, ?) desc limit 5",
                ['jon smth', '%jon smth%', 'jon smth'],
            ],
            'search — deals (trigram, titlu)' => [
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
