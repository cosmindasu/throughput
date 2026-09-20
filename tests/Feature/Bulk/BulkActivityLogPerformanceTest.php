<?php

namespace Tests\Feature\Bulk;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BulkOperation;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Raportul lotului E, pct. 6 — „scrierea e asincronă ca să nu încarce cererea. Verifică
 * EMPIRIC, nu prin raționament, costul pe o operație în masă mare (seed + măsurătoare),
 * EXPLAIN pe interogarea tabului History sub RLS, ca throughput_app."
 *
 * Seed-ul de 2.000 de conturi ocolește DELIBERAT Eloquent (`Account::insert()`, ca
 * `Database\Seeders\Support\ChunkedWriter`) — un `Account::factory()->count(2000)->create()`
 * ar trece prin observerul de activitate PENTRU SEED, poluând măsurătoarea cu 2.000 de
 * scrieri care n-au nicio legătură cu ce se măsoară aici (bulk-ul).
 */
class BulkActivityLogPerformanceTest extends TestCase
{
    private const ROW_COUNT = 2000;

    public function test_bulk_reassign_with_activity_log_instrumentation_on_2000_rows(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);
        $newOwner = $this->makeMember($tenant, 'new-owner@throughput.dev', Permissions::MANAGER);
        $this->clearDatabaseTenantContext();

        TenantContext::run($tenant, function () use ($tenant, $owner): void {
            $now = now();
            $rows = [];

            for ($i = 0; $i < self::ROW_COUNT; $i++) {
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'tenant_id' => $tenant->getKey(),
                    'name' => "Perf Account {$i}",
                    'owner_user_id' => $owner->getKey(),
                    'status' => Account::STATUS_ACTIVE,
                    'created_by' => $owner->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Account::insert($chunk);
            }
        });
        $this->clearDatabaseTenantContext();

        $start = microtime(true);

        $response = $this->actingAs($owner)->post(
            '/marlin/accounts/bulk/reassign-owner?filter[status]=active',
            ['selectAllMatching' => true, 'owner_user_id' => $newOwner->getKey(), 'confirmed' => true],
        );
        $response->assertRedirect();

        $operation = TenantContext::run(
            $tenant,
            fn () => BulkOperation::query()->where('resource_type', 'accounts')->where('action', 'reassign_owner')->firstOrFail(),
        );

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--stop-when-empty' => true, '--no-interaction' => true]);

        $elapsed = round(microtime(true) - $start, 2);
        fwrite(STDERR, "\n[BulkActivityLogPerformanceTest] {".self::ROW_COUNT."}-row reassign, WITH activity_log instrumentation (read-before/apply/read-after + batch insert): {$elapsed}s\n");

        $logCount = TenantContext::run(
            $tenant,
            fn () => ActivityLog::query()->where('bulk_operation_id', $operation->getKey())->count(),
        );

        // Un rând de jurnal PER cont efectiv reasignat — nu unul per chunk.
        $this->assertSame(self::ROW_COUNT, $logCount);

        // Gardă de regresie GENEROASĂ (nu un SLA strict — cifra din STDERR de mai sus e ce
        // contează pentru raport), ca un viitor N+1 introdus accidental să pice testul.
        $this->assertLessThan(60.0, $elapsed);

        // EXPLAIN — US-BULK-01, „activity_log filtrat pe această operație" — CA
        // `throughput_app` (conexiunea de test, RLS activ, NU BYPASSRLS).
        TenantContext::run($tenant, function () use ($operation): void {
            $plan = $this->explainPlanText(
                'SELECT * FROM activity_log WHERE tenant_id = current_setting(\'app.tenant_id\', true)::bpchar AND bulk_operation_id = ?',
                [$operation->getKey()],
            );

            fwrite(STDERR, "\n[BulkActivityLogPerformanceTest] EXPLAIN — activity_log WHERE bulk_operation_id:\n{$plan}\n");

            $this->assertStringContainsString('Index', $plan, 'Indexul (tenant_id, bulk_operation_id) ar trebui folosit, nu un Seq Scan.');
        });

        // EXPLAIN — tabul „History" al UNEI entități (indexul deja existent din Faza 1,
        // `(tenant_id, auditable_type, auditable_id)`), pe același set de date de volum.
        TenantContext::run($tenant, function (): void {
            $anyAccountId = Account::query()->value('id');

            $plan = $this->explainPlanText(
                'SELECT * FROM activity_log WHERE tenant_id = current_setting(\'app.tenant_id\', true)::bpchar AND auditable_type = ? AND auditable_id = ?',
                [Account::class, $anyAccountId],
            );

            fwrite(STDERR, "\n[BulkActivityLogPerformanceTest] EXPLAIN — activity_log tab History (auditable_type/id):\n{$plan}\n");

            $this->assertStringContainsString('Index', $plan);
        });
    }

    /** @param  list<mixed>  $bindings */
    private function explainPlanText(string $sql, array $bindings): string
    {
        $rows = DB::select('EXPLAIN '.$sql, $bindings);

        return collect($rows)->map(fn ($row) => $row->{'QUERY PLAN'})->implode("\n");
    }
}
