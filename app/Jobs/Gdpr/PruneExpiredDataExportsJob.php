<?php

namespace App\Jobs\Gdpr;

use App\Actions\Gdpr\DataExportPaths;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * FR-GDPR-01, US-GDPR-01 (gherkin-ul de curățare) — jobul zilnic care face linkul de
 * descărcare să moară: „fișierul e șters din storage și linkul devine invalid, dar rândul
 * din istoric rămâne, cu status neschimbat".
 *
 * **Job NOU, nu o extindere a lui `App\Jobs\System\PruneExpiredExportsJob`.** Acela mătură
 * `exports/` (exportul de LISTĂ, `bulk_operations`, §13.2) și e fișierul altui lot; cele
 * două retenții au aceeași durată (`export_retention_days`, 7 zile) și același motiv, dar
 * rădăcini separate pe disc — vezi `App\Actions\Gdpr\DataExportPaths` pentru de ce
 * `gdpr-exports/` nu poate sta sub `exports/` (a treia trecere a acelui job ar șterge
 * folderul întreg).
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4): fără tenant propriu, iterează
 * tenanții explicit, cu o tranzacție și un context per tenant, pe conexiunea aplicației —
 * niciodată pe cea cu BYPASSRLS.
 *
 * Trei treceri, simetrice cu jobul de exporturi de listă:
 *  1. **Expirare** — rândurile cu `expires_at` trecut: fișierul se șterge, `file_path` se
 *     golește, `status` rămâne neschimbat (istoricul e dovada că cererea a fost onorată).
 *  2. **Orfani** — arhive și directoare de lucru nereferite de niciun rând (un job întrerupt
 *     între `Storage::put()` și `update()`), mai vechi decât retenția, după mtime: pragul de
 *     vârstă evită ștergerea unui export încă în lucru.
 *  3. **Foldere de tenanți dispăruți** — `demo:reset` reface `tenants` cu alte ULID-uri, deci
 *     nicio interogare RLS nu mai poate confirma vreun rând; fișierele sunt orfane
 *     necondiționat, tot după prag.
 *
 * Idempotent: `delete()` pe un fișier absent nu aruncă (`filesystems.php`: `throw => false`),
 * iar golirea unui `file_path` deja gol e un no-op.
 */
class PruneExpiredDataExportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $cutoff = now()->subDays($this->retentionDays())->getTimestamp();

        /** @var array<string, true> $knownTenantIds */
        $knownTenantIds = [];

        Tenant::query()->eachById(function (Tenant $tenant) use (&$knownTenantIds, $cutoff): void {
            $knownTenantIds[$tenant->getKey()] = true;

            TenantContext::run($tenant, function () use ($tenant, $cutoff): void {
                $this->expireArchives();
                $this->sweepOrphans($tenant->getKey(), $cutoff);
            });
        });

        $this->sweepAbandonedTenantFolders($knownTenantIds, $cutoff);
    }

    private function retentionDays(): int
    {
        return (int) config('throughput.limits.export_retention_days');
    }

    /**
     * `eachById`, nu `each()`/`chunk()`: golirea lui `file_path` scoate rândul din
     * `whereNotNull('file_path')`, deci o paginare pe OFFSET ar sări rânduri la fiecare
     * chunk. `chunkById` (folosit de `eachById`) se ține de cursorul pe `id`, neafectat.
     */
    private function expireArchives(): void
    {
        DataExportRequest::query()
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->eachById(function (DataExportRequest $export): void {
                Storage::disk(DataExportPaths::DISK)->delete($export->file_path);
                $export->update(['file_path' => null]);
            });
    }

    private function sweepOrphans(string $tenantId, int $cutoff): void
    {
        $disk = Storage::disk(DataExportPaths::DISK);
        $folder = DataExportPaths::tenantFolder($tenantId);

        if (! $disk->exists($folder)) {
            return;
        }

        // Trecerea de mai sus a golit deja `file_path`-urile expirate — ce mai rămâne
        // referit sunt arhivele încă valabile.
        $referenced = array_flip(
            DataExportRequest::query()->whereNotNull('file_path')->pluck('file_path')->all()
        );

        foreach ($disk->files($folder) as $file) {
            if (isset($referenced[$file])) {
                continue;
            }

            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }

        // Directoarele de lucru: un export întrerupt între scrierea părților și arhivare
        // lasă în urmă un director cu JSON-uri care nu vor fi niciodată livrate.
        foreach ($disk->directories($folder) as $workFolder) {
            if ($this->newestFile($workFolder) >= $cutoff) {
                continue;
            }

            $disk->deleteDirectory($workFolder);
        }
    }

    /**
     * @param  array<string, true>  $knownTenantIds
     */
    private function sweepAbandonedTenantFolders(array $knownTenantIds, int $cutoff): void
    {
        $disk = Storage::disk(DataExportPaths::DISK);

        foreach ($disk->directories(DataExportPaths::ROOT) as $folder) {
            if (isset($knownTenantIds[basename($folder)])) {
                continue;
            }

            if ($this->newestFile($folder) >= $cutoff) {
                continue;
            }

            $disk->deleteDirectory($folder);
        }
    }

    /**
     * Cel mai recent `mtime` din folder, recursiv. `0` pentru un folder gol — deci un
     * director rămas fără fișiere se curăță la prima trecere.
     */
    private function newestFile(string $folder): int
    {
        $disk = Storage::disk(DataExportPaths::DISK);
        $newest = 0;

        foreach ($disk->allFiles($folder) as $file) {
            $newest = max($newest, $disk->lastModified($file));
        }

        return $newest;
    }
}
