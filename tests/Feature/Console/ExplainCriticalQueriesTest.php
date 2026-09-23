<?php

namespace Tests\Feature\Console;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `db:explain-critical` — cazul „activity_log — filtrat pe utilizator (Agent, PERF-05)”
 * (audit 2026-09-23, §2), adăugat pentru interogarea EXACTĂ din
 * `ActivityLogController::index()` linia 53: un Agent (`activity_log.view_own`, fără
 * `activity_log.view`) primește necondiționat `where('user_id', ...)` peste
 * `orderByDesc('created_at')->orderByDesc('id')`.
 *
 * Comanda nu poate verifica planul real (Seq Scan → Index Scan) pe un seed de test —
 * docblock-ul clasei explică de ce: fără volum, `reltuples` rămâne sub orice prag
 * rezonabil, exact capcana „imposibil de observat pe un seed mic” pe care regula de
 * `--threshold` există s-o evite. Planul real, măsurat pe seed-ul complet
 * (`demo:seed-volume`, tenant Marlin, 108.166 rânduri de `activity_log`), e documentat în
 * migrația `2026_09_23_100000_add_user_id_index_to_activity_log_table` (49,2 ms cu `Bitmap
 * Heap Scan` pe tot tenantul ÎNAINTE de index, 0,5 ms cu `Index Scan Backward` DUPĂ).
 *
 * Ce verifică acest test, pe un seed mic: interogarea e SQL valid, rulează prin
 * `EXPLAIN (ANALYZE, FORMAT JSON)` sub contextul de tenant/RLS setat de comandă fără nicio
 * eroare, și cazul apare în output cu starea de succes — o gardă împotriva unei regresii de
 * sintaxă sau de nume de coloană, nu o dovadă de plan.
 */
class ExplainCriticalQueriesTest extends TestCase
{
    public function test_the_agent_filtered_activity_log_case_runs_without_error_and_succeeds(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $agent = $this->makeMember($tenant, 'agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($tenant, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($tenant, function () use ($agent, $viewer): void {
            for ($i = 0; $i < 3; $i++) {
                ActivityLog::query()->create([
                    'user_id' => $agent->getKey(),
                    'action' => 'updated',
                    'auditable_type' => 'App\\Models\\Account',
                    'auditable_id' => (string) Str::ulid(),
                    'old_values' => ['name' => 'Old'],
                    'new_values' => ['name' => 'New'],
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PestTest/1.0',
                ]);
            }

            ActivityLog::query()->create([
                'user_id' => $viewer->getKey(),
                'action' => 'login',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->artisan('db:explain-critical', ['--tenant' => $tenant->slug])
            ->assertSuccessful()
            ->expectsOutputToContain('activity_log — filtrat pe utilizator (Agent, PERF-05)');
    }

    /**
     * Tenantul resolvat n-are nicio intrare `activity_log`: subinterogarea care alege
     * dinamic un `user_id` (`select user_id from activity_log order by user_id limit 1`)
     * întoarce `NULL`, deci `where user_id = NULL` — SQL valid, zero rânduri, niciun plan
     * cu noduri de scanare pe care `sequentialScans()` să le găsească. Comanda tot trebuie
     * să treacă, nu să arunce o excepție la decodarea planului.
     */
    public function test_the_case_does_not_error_when_the_tenant_has_no_activity_log_rows(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($tenant, 'agent@throughput.dev', Permissions::AGENT);

        $this->artisan('db:explain-critical', ['--tenant' => $tenant->slug])
            ->assertSuccessful()
            ->expectsOutputToContain('activity_log — filtrat pe utilizator (Agent, PERF-05)');
    }
}
