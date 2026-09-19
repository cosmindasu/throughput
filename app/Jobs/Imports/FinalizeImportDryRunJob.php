<?php

namespace App\Jobs\Imports;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\Import;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportDryRunFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ultimul pas al probei uscate — dispecerizat de `RunDryRunValidationJob` după ultimul chunk
 * de fișier. Job SEPARAT (nu inline în ultimul chunk): fiecare invocare rămâne o unitate de
 * lucru mică și previzibilă sub `timeout = 60` (Horizon, `config/horizon.php`) — un fișier
 * la plafon (50.000 rânduri) ar însuma AICI citirea/scrierea ultimului chunk ȘI trecerea de
 * finalizare (`ImportDryRunFinalizer`, ~100 interogări chunk-uite) în ACEEAȘI fereastră dacă
 * ar fi inline, ceea ce apropie inutil de plafon fără niciun câștig.
 */
class FinalizeImportDryRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(
        public string $tenantId,
        public string $importId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null || $import->status !== Import::STATUS_VALIDATING) {
            return;
        }

        $resource = ImportableResources::resolve($import->resource_type);
        ImportDryRunFinalizer::run($this->importId, $resource, (array) $import->column_mapping);

        $import->fresh()?->update(['status' => Import::STATUS_VALIDATED]);
    }

    public function failed(Throwable $e): void
    {
        TenantContext::run($this->tenantId, function (): void {
            // `completed_at` — vezi nota din `CommitImportJob::failed()` (retenția fișierului,
            // `PruneExpiredImportFilesJob`).
            Import::query()->whereKey($this->importId)->update([
                'status' => Import::STATUS_FAILED,
                'completed_at' => now(),
            ]);
        });

        report($e);
    }
}
