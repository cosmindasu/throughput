<?php

namespace Tests\Feature\Exports;

use App\Jobs\System\PruneExpiredExportsJob;
use App\Models\BulkOperation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan §7.2 („curățarea exporturilor expirate") + specs.md FR-GDPR-01/§20.5 (aceeași
 * retenție). Gap-ul confirmat înainte de acest pachet: `app/Jobs/System/` avea doar
 * `ResetDemoDataJob`, `routes/console.php` nu programa nimic pentru `exports/`, iar
 * `demo:reset` (`migrate:fresh`) golea `bulk_operations` fără să atingă discul — fișierele
 * CSV (date de contact, în demo-ul public cu scriere reală) rămâneau la nesfârșit. Testele de
 * mai jos verifică fix mecanismul care lipsea: fără `PruneExpiredExportsJob` și fără intrarea
 * din scheduler, `test_the_job_is_scheduled_daily` și toate celelalte ar eșua imediat (clasă
 * inexistentă / eveniment inexistent) — asta e demonstrația gap-ului, nu un test separat care
 * ar rămâne roșu permanent.
 */
class PruneExpiredExportsJobTest extends TestCase
{
    private Tenant $marlin;

    private User $marlinOwner;

    private Tenant $cascade;

    private User $cascadeOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->marlinOwner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->cascadeOwner = $this->makeMember($this->cascade, 'demo.cascade-owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_it_deletes_the_file_and_clears_the_path_for_expired_exports_while_keeping_valid_ones(): void
    {
        $expired = $this->makeCompletedExport($this->marlin, $this->marlinOwner, now()->subDay());
        $valid = $this->makeCompletedExport($this->marlin, $this->marlinOwner, now()->addDays(3));
        $this->clearDatabaseTenantContext();

        Storage::disk('local')->assertExists($expired->result_path);
        Storage::disk('local')->assertExists($valid->result_path);
        $expiredPath = $expired->result_path;
        $validPath = $valid->result_path;

        (new PruneExpiredExportsJob)->handle();

        Storage::disk('local')->assertMissing($expiredPath);
        Storage::disk('local')->assertExists($validPath);

        $expiredFresh = TenantContext::run($this->marlin, fn () => $expired->fresh());
        $validFresh = TenantContext::run($this->marlin, fn () => $valid->fresh());

        $this->assertNull($expiredFresh->result_path, 'result_path trebuie golit la expirare.');
        $this->assertSame(
            BulkOperation::STATUS_COMPLETED,
            $expiredFresh->status,
            'Rândul și statusul rămân — doar result_path se golește (US-GDPR-01).'
        );
        $this->assertSame($validPath, $validFresh->result_path, 'Un export încă valabil nu trebuie atins.');
    }

    public function test_it_runs_correctly_across_multiple_tenants_under_rls(): void
    {
        $marlinExpired = $this->makeCompletedExport($this->marlin, $this->marlinOwner, now()->subHour());
        $cascadeExpired = $this->makeCompletedExport($this->cascade, $this->cascadeOwner, now()->subHour());
        $cascadeValid = $this->makeCompletedExport($this->cascade, $this->cascadeOwner, now()->addDays(2));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredExportsJob)->handle();

        $this->assertNull(TenantContext::run($this->marlin, fn () => $marlinExpired->fresh()->result_path));
        $this->assertNull(TenantContext::run($this->cascade, fn () => $cascadeExpired->fresh()->result_path));
        $this->assertNotNull(TenantContext::run($this->cascade, fn () => $cascadeValid->fresh()->result_path));

        // Contextul nu rămâne legat de ultimul tenant din buclă (ADR-014, `TenantContext::run`
        // restaurează la ieșire) — altfel o interogare de după job ar scopa tăcut pe cascade.
        $this->assertNull(TenantScope::currentTenantId());
    }

    public function test_it_sweeps_orphan_files_older_than_retention_and_keeps_recent_ones(): void
    {
        $oldOrphan = $this->putOrphanFile($this->marlin, 'old-orphan.csv');
        $this->backdate($oldOrphan, now()->subDays(30));

        $recentOrphan = $this->putOrphanFile($this->marlin, 'recent-orphan.csv');

        (new PruneExpiredExportsJob)->handle();

        Storage::disk('local')->assertMissing($oldOrphan);
        Storage::disk('local')->assertExists($recentOrphan);
    }

    public function test_it_does_not_touch_a_file_still_referenced_by_a_running_export(): void
    {
        // Un job de export încă neterminat: rândul există, dar `expires_at` nu e setat
        // încă (se pune abia la `completed`) — fișierul, dacă există deja, nu e orfan.
        $path = 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.csv';

        $operation = TenantContext::run($this->marlin, function () use ($path) {
            Storage::disk('local')->put($path, "Name\n");

            return BulkOperation::query()->create([
                'user_id' => $this->marlinOwner->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
                'total_rows' => 1,
                'status' => BulkOperation::STATUS_RUNNING,
                'result_path' => $path,
                'expires_at' => null,
            ]);
        });
        $this->backdate($path, now()->subDays(30));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredExportsJob)->handle();

        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, TenantContext::run($this->marlin, fn () => $operation->fresh()->result_path));
    }

    public function test_it_sweeps_files_left_behind_by_a_tenant_that_no_longer_exists(): void
    {
        $abandonedTenantId = (string) Str::ulid();
        $oldFile = "exports/{$abandonedTenantId}/old.csv";
        $recentFile = "exports/{$abandonedTenantId}/recent.csv";

        Storage::disk('local')->put($oldFile, "Name\n");
        $this->backdate($oldFile, now()->subDays(30));
        Storage::disk('local')->put($recentFile, "Name\n");

        (new PruneExpiredExportsJob)->handle();

        Storage::disk('local')->assertMissing($oldFile);
        // Folderul nu e gol (mai are `recent.csv`), deci rămâne — dovadă indirectă că
        // fișierul proaspăt n-a fost șters.
        Storage::disk('local')->assertExists($recentFile);
    }

    public function test_rows_without_a_result_path_or_without_an_expiry_are_left_alone(): void
    {
        // O operație în masă de scriere (nu export): fără result_path, fără expires_at.
        $reassign = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->marlinOwner->getKey(),
            'resource_type' => 'accounts',
            'action' => 'reassign_owner',
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => BulkOperation::STATUS_COMPLETED,
        ]));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredExportsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $reassign->fresh());
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $fresh->status);
        $this->assertNull($fresh->result_path);
    }

    public function test_it_is_idempotent_on_a_second_run(): void
    {
        $expired = $this->makeCompletedExport($this->marlin, $this->marlinOwner, now()->subDay());
        $valid = $this->makeCompletedExport($this->marlin, $this->marlinOwner, now()->addDays(3));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredExportsJob)->handle();
        (new PruneExpiredExportsJob)->handle();

        $expiredFresh = TenantContext::run($this->marlin, fn () => $expired->fresh());
        $validFresh = TenantContext::run($this->marlin, fn () => $valid->fresh());

        $this->assertNull($expiredFresh->result_path);
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $expiredFresh->status);
        $this->assertNotNull($validFresh->result_path);
        Storage::disk('local')->assertExists($validFresh->result_path);
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === PruneExpiredExportsJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru curățarea exporturilor lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit.');
    }

    private function makeCompletedExport(Tenant $tenant, User $user, \DateTimeInterface $expiresAt): BulkOperation
    {
        return TenantContext::run($tenant, function () use ($tenant, $user, $expiresAt) {
            $path = 'exports/'.$tenant->getKey().'/'.Str::ulid().'.csv';
            Storage::disk('local')->put($path, "Name\nAcme\n");

            return BulkOperation::query()->create([
                'user_id' => $user->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
                'total_rows' => 1,
                'status' => BulkOperation::STATUS_COMPLETED,
                'result_path' => $path,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    private function putOrphanFile(Tenant $tenant, string $name): string
    {
        $path = 'exports/'.$tenant->getKey().'/'.$name;
        Storage::disk('local')->put($path, "Name\n");

        return $path;
    }

    private function backdate(string $path, \DateTimeInterface $when): void
    {
        touch(Storage::disk('local')->path($path), $when->getTimestamp());
    }
}
