<?php

namespace Tests\Feature\Imports;

use App\Jobs\System\PruneExpiredImportFilesJob;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ImportFilePath;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P1, review general — fișierul încărcat nu se ștergea niciodată. `raw_data` (BR-IMP-01) NU
 * se atinge; doar fișierul brut de pe disc.
 */
class PruneExpiredImportFilesJobTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ImportFilePath::DISK);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        config(['throughput.limits.import_retention_days' => 7]);
    }

    public function test_the_file_of_a_terminal_import_past_retention_is_deleted_but_raw_data_survives(): void
    {
        $import = $this->makeCompletedImport(now()->subDays(10));
        $path = TenantContext::run($this->marlin, fn () => ImportFilePath::for($import->fresh()));

        Storage::disk(ImportFilePath::DISK)->put($path, 'sku,name');
        $this->assertTrue(Storage::disk(ImportFilePath::DISK)->exists($path));

        $row = TenantContext::run($this->marlin, function () use ($import) {
            $row = new ImportRow(['import_id' => $import->getKey(), 'row_number' => 2, 'raw_data' => ['sku' => 'A'], 'status' => ImportRow::STATUS_IMPORTED]);
            $row->save();

            return $row;
        });

        (new PruneExpiredImportFilesJob)->handle();

        $this->assertFalse(Storage::disk(ImportFilePath::DISK)->exists($path));

        $freshRow = TenantContext::run($this->marlin, fn () => $row->fresh());
        $this->assertSame(['sku' => 'A'], $freshRow->raw_data, 'BR-IMP-01 — raw_data nu se șterge niciodată.');
    }

    public function test_a_terminal_import_still_within_retention_keeps_its_file(): void
    {
        $import = $this->makeCompletedImport(now()->subDays(2));
        $path = TenantContext::run($this->marlin, fn () => ImportFilePath::for($import->fresh()));

        Storage::disk(ImportFilePath::DISK)->put($path, 'sku,name');

        (new PruneExpiredImportFilesJob)->handle();

        $this->assertTrue(Storage::disk(ImportFilePath::DISK)->exists($path));
    }

    public function test_a_non_terminal_import_keeps_its_file_regardless_of_age(): void
    {
        $import = TenantContext::run($this->marlin, function () {
            $import = new Import(['resource_type' => 'products', 'original_filename' => 'products.csv', 'status' => Import::STATUS_MAPPED]);
            $import->created_by = $this->owner->getKey();
            $import->save();
            DB::table('imports')->where('id', $import->getKey())->update(['updated_at' => now()->subDays(30)]);

            return $import;
        });

        $path = TenantContext::run($this->marlin, fn () => ImportFilePath::for($import->fresh()));
        Storage::disk(ImportFilePath::DISK)->put($path, 'sku,name');

        (new PruneExpiredImportFilesJob)->handle();

        $this->assertTrue(Storage::disk(ImportFilePath::DISK)->exists($path));
    }

    private function makeCompletedImport(Carbon $completedAt): Import
    {
        return TenantContext::run($this->marlin, function () use ($completedAt): Import {
            $import = new Import([
                'resource_type' => 'products',
                'original_filename' => 'products.csv',
                'status' => Import::STATUS_COMPLETED,
                'completed_at' => $completedAt,
            ]);
            $import->created_by = $this->owner->getKey();
            $import->save();

            return $import;
        });
    }
}
