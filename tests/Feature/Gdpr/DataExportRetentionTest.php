<?php

namespace Tests\Feature\Gdpr;

use App\Actions\Gdpr\DataExportPaths;
use App\Jobs\Gdpr\PruneExpiredDataExportsJob;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-GDPR-01, US-GDPR-01 (gherkin-ul de curățare, specs.md §20.5):
 *
 *   Given un export cu status „completed" mai vechi de 7 zile
 *   When rulează job-ul zilnic de curățare
 *   Then fișierul e șters din storage și linkul devine invalid, dar rândul din istoric
 *   rămâne, cu status neschimbat
 */
class DataExportRetentionTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Tenant $cascade;

    private User $cascadeOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->cascadeOwner = $this->makeMember($this->cascade, 'demo.cascade-owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_it_deletes_the_expired_archive_clears_the_path_and_keeps_the_row(): void
    {
        $expired = $this->completedExport($this->marlin, $this->owner, now()->subDay());
        $valid = $this->completedExport($this->marlin, $this->owner, now()->addDays(3));

        $expiredPath = $expired->file_path;
        $validPath = $valid->file_path;

        Storage::disk('local')->assertExists($expiredPath);

        (new PruneExpiredDataExportsJob)->handle();

        Storage::disk('local')->assertMissing($expiredPath);
        Storage::disk('local')->assertExists($validPath);

        $fresh = TenantContext::run($this->marlin, fn () => $expired->fresh());
        $this->clearDatabaseTenantContext();

        $this->assertNull($fresh->file_path, 'file_path se golește la expirare.');
        $this->assertSame(
            DataExportRequest::STATUS_COMPLETED,
            $fresh->status,
            'Rândul din istoric rămâne, cu status neschimbat (US-GDPR-01).',
        );
        $this->assertNotNull($fresh->requested_at);
    }

    public function test_it_runs_across_tenants_under_rls_without_touching_valid_archives(): void
    {
        $marlinExpired = $this->completedExport($this->marlin, $this->owner, now()->subHour());
        $cascadeExpired = $this->completedExport($this->cascade, $this->cascadeOwner, now()->subHour());
        $cascadeValid = $this->completedExport($this->cascade, $this->cascadeOwner, now()->addDay());

        $paths = [$marlinExpired->file_path, $cascadeExpired->file_path, $cascadeValid->file_path];

        (new PruneExpiredDataExportsJob)->handle();

        Storage::disk('local')->assertMissing($paths[0]);
        Storage::disk('local')->assertMissing($paths[1]);
        Storage::disk('local')->assertExists($paths[2]);
    }

    /**
     * Linkul moare la scadență, indiferent dacă jobul zilnic a apucat să ruleze — de-asta
     * `DataExportRequestPolicy::download()` verifică TIMPUL, nu doar prezența fișierului.
     */
    public function test_the_download_link_stops_working_at_expiry_even_before_the_job_runs(): void
    {
        $expired = $this->completedExport($this->marlin, $this->owner, now()->subMinute());

        $this->actingAs($this->owner)
            ->get("/marlin/settings/data-export/{$expired->getKey()}/download")
            ->assertForbidden();
    }

    public function test_a_missing_file_on_a_still_valid_row_is_refused_with_a_message_not_a_500(): void
    {
        $export = $this->completedExport($this->marlin, $this->owner, now()->addDay());

        Storage::disk('local')->delete($export->file_path);

        $this->actingAs($this->owner)
            ->get("/marlin/settings/data-export/{$export->getKey()}/download")
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_it_sweeps_orphan_work_folders_and_folders_of_tenants_that_no_longer_exist(): void
    {
        $disk = Storage::disk('local');

        // Director de lucru rămas de la un export întrerupt înainte de arhivare.
        $orphanWork = DataExportPaths::workFolder($this->marlin->getKey(), (string) Str::ulid());
        $disk->put("{$orphanWork}/accounts.json", '[]');
        touch($disk->path("{$orphanWork}/accounts.json"), now()->subDays(30)->getTimestamp());

        // Folderul unui tenant care nu mai există (`demo:reset` reface ULID-urile).
        $ghostFolder = DataExportPaths::tenantFolder((string) Str::ulid());
        $disk->put("{$ghostFolder}/old.zip", 'zip');
        touch($disk->path("{$ghostFolder}/old.zip"), now()->subDays(30)->getTimestamp());

        // Arhivă recentă, nereferită: încă în lucru, deci NU se atinge.
        $recentOrphan = DataExportPaths::tenantFolder($this->marlin->getKey()).'/fresh.zip';
        $disk->put($recentOrphan, 'zip');

        (new PruneExpiredDataExportsJob)->handle();

        $disk->assertMissing("{$orphanWork}/accounts.json");
        $disk->assertMissing("{$ghostFolder}/old.zip");
        $disk->assertExists($recentOrphan);
    }

    private function completedExport(Tenant $tenant, User $user, \DateTimeInterface $expiresAt): DataExportRequest
    {
        $export = TenantContext::run($tenant, function () use ($tenant, $user, $expiresAt): DataExportRequest {
            $export = new DataExportRequest([
                'status' => DataExportRequest::STATUS_COMPLETED,
                'requested_at' => now()->subDays(8),
                'completed_at' => now()->subDays(8),
            ]);
            $export->requested_by = $user->getKey();
            $export->save();

            $path = DataExportPaths::archive($tenant->getKey(), $export->getKey());
            Storage::disk('local')->put($path, 'zip-bytes');

            $export->update(['file_path' => $path, 'expires_at' => $expiresAt]);

            return $export;
        });

        $this->clearDatabaseTenantContext();

        return $export;
    }
}
