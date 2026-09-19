<?php

namespace App\Jobs\System;

use App\Models\Import;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ImportFilePath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * P1 (review general) — `ImportFilePath` scrie fișierul încărcat la `imports/{tenant}/
 * {id}.{ext}` și nimic nu-l ștergea vreodată. Fișierul brut conține și coloanele NEMAPATE,
 * deci date de business care n-au ajuns niciodată în aplicație — pe un produs cu retenție
 * explicită (§20.5), un gol real. `demo:reset` (`App\Console\Commands\DemoReset`) golește
 * `imports/` separat, în afara acestui job (reset-ul șterge rândurile din bază oricum, deci
 * fișierele orfane trebuie curățate la reset, nu doar așteptând retenția de 7 zile).
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4), structurat ca
 * `PruneExpiredExportsJob`: fără tenant, iterează tenanții explicit, cu un context per
 * tenant, pe conexiunea aplicației.
 *
 * ȘTERGE DOAR FIȘIERUL de pe disc — `import_rows.raw_data` rămâne neatins (BR-IMP-01, cerut
 * pentru raportul reimportabil chiar și după finalizare); rândul `imports` însuși rămâne
 * pentru istoric, la fel ca `bulk_operations` după expirarea unui export.
 *
 * Idempotent: `delete()` pe un fișier absent nu aruncă (`filesystems.php`: `throw => false`).
 */
class PruneExpiredImportFilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    private const TERMINAL_STATUSES = [
        Import::STATUS_COMPLETED,
        Import::STATUS_COMPLETED_WITH_ERRORS,
        Import::STATUS_FAILED,
    ];

    public function handle(): void
    {
        $cutoff = now()->subDays($this->retentionDays());

        /** @var array<string, true> $knownTenantIds */
        $knownTenantIds = [];

        Tenant::query()->eachById(function (Tenant $tenant) use (&$knownTenantIds, $cutoff): void {
            $knownTenantIds[$tenant->getKey()] = true;

            TenantContext::run($tenant, function () use ($cutoff): void {
                $this->pruneTerminalImportFiles($cutoff);
            });
        });

        $this->sweepAbandonedTenantFolders($knownTenantIds, $cutoff->getTimestamp());
    }

    private function retentionDays(): int
    {
        return (int) config('throughput.limits.import_retention_days');
    }

    /**
     * `eachById`, nu `each()`/`chunk()` — la fel ca `PruneExpiredExportsJob::expireCompletedExports()`:
     * nu golim nicio coloană care ar scoate rândul din filtru la pagina următoare, dar rămânem
     * pe același tipar defensiv (paginare pe cursor de id, nu offset).
     */
    private function pruneTerminalImportFiles(Carbon $cutoff): void
    {
        Import::query()
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where('completed_at', '<', $cutoff)
            ->eachById(function (Import $import): void {
                $path = ImportFilePath::for($import);

                if (Storage::disk(ImportFilePath::DISK)->exists($path)) {
                    Storage::disk(ImportFilePath::DISK)->delete($path);
                }
            });
    }

    /**
     * Foldere de tenanți care nu mai există deloc (`demo:reset` reface `tenants` cu alte
     * ULID-uri prin `migrate:fresh`) — plasă simetrică cu
     * `PruneExpiredExportsJob::sweepAbandonedTenantFolders()`, pentru cazul (neașteptat în
     * producție, unde reset-ul golește deja `imports/`) în care folderul supraviețuiește.
     *
     * @param  array<string, true>  $knownTenantIds
     */
    private function sweepAbandonedTenantFolders(array $knownTenantIds, int $cutoffTimestamp): void
    {
        foreach (Storage::disk(ImportFilePath::DISK)->directories('imports') as $folder) {
            if (isset($knownTenantIds[basename($folder)])) {
                continue;
            }

            foreach (Storage::disk(ImportFilePath::DISK)->files($folder) as $file) {
                if (Storage::disk(ImportFilePath::DISK)->lastModified($file) < $cutoffTimestamp) {
                    Storage::disk(ImportFilePath::DISK)->delete($file);
                }
            }

            if (Storage::disk(ImportFilePath::DISK)->files($folder) === []) {
                Storage::disk(ImportFilePath::DISK)->deleteDirectory($folder);
            }
        }
    }
}
