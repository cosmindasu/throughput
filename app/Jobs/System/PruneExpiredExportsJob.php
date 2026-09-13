<?php

namespace App\Jobs\System;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Plan §7.2 (lista joburilor de sistem: „curățarea exporturilor expirate") și §8; specs.md
 * FR-GDPR-01/§20.5 — aceeași retenție (`throughput.limits.export_retention_days`, implicit 7
 * zile) e aplicată aici pe `bulk_operations` (exportul de listă, §13.2), singurul mecanism
 * care scrie azi fișiere în `exports/`. `data_export_requests` (exportul GDPR de tenant) încă
 * n-are cod de scriere — Faza 5 — deci n-are ce curăța.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4): fără tenant, iterează tenanții
 * explicit, cu o tranzacție și un context per tenant, pe conexiunea aplicației — NICIODATĂ pe
 * cea cu BYPASSRLS, rezervată exclusiv lui `artisan migrate`/`demo:reset`.
 *
 * Două treceri, per tenant:
 *  1. **Expirare** — rândurile `bulk_operations` cu `expires_at` trecut: fișierul se șterge,
 *     `result_path` se golește, rândul și `status` rămân neschimbate (US-GDPR-01, gherkin-ul
 *     de curățare: „linkul devine invalid, dar rândul din istoric rămâne").
 *  2. **Orfani** — fișiere din `exports/{tenant}` fără niciun rând care să le refere (de ex.
 *     un job întrerupt între `Storage::put()` și `update()`), mai vechi decât retenția, după
 *     mtime — pragul de vârstă evită să șteargă fișierul unui export încă în lucru.
 *
 * O a treia trecere curăță folderele de tenanți care nu mai există deloc (`demo:reset` reface
 * `tenants` cu alte ULID-uri prin `migrate:fresh`): fără tenant, nicio interogare RLS nu poate
 * confirma un rând, deci fișierele sunt orfane necondiționat — tot după retenție, ca plasă
 * pentru cazul (neașteptat în producție, unde reset-ul golește deja `exports/`) în care
 * folderul a supraviețuit unui reset.
 *
 * Idempotent: `delete()` pe un fișier absent nu aruncă (`filesystems.php`: `throw => false`),
 * iar golirea unui `result_path` deja gol e un no-op.
 */
class PruneExpiredExportsJob implements ShouldQueue
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
                $this->expireCompletedExports();
                $this->sweepOrphanFiles($tenant->getKey(), $cutoff);
            });
        });

        $this->sweepAbandonedTenantFolders($knownTenantIds, $cutoff);
    }

    private function retentionDays(): int
    {
        return (int) config('throughput.limits.export_retention_days');
    }

    /**
     * `eachById`, nu `each()`/`chunk()`: golirea lui `result_path` scoate rândul din
     * `whereNotNull('result_path')`, deci o paginare pe OFFSET ar sări rânduri la fiecare
     * chunk. `chunkById` (folosit de `eachById`) se ține de cursorul pe `id`, neafectat.
     */
    private function expireCompletedExports(): void
    {
        BulkOperation::query()
            ->whereNotNull('result_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->eachById(function (BulkOperation $operation): void {
                Storage::disk('local')->delete($operation->result_path);
                $operation->update(['result_path' => null]);
            });
    }

    private function sweepOrphanFiles(string $tenantId, int $cutoff): void
    {
        $folder = "exports/{$tenantId}";

        if (! Storage::disk('local')->exists($folder)) {
            return;
        }

        // Trecerea de mai sus a golit deja `result_path`-urile expirate — ce mai rămâne
        // referit sunt exporturile încă valabile.
        $referenced = array_flip(
            BulkOperation::query()->whereNotNull('result_path')->pluck('result_path')->all()
        );

        foreach (Storage::disk('local')->files($folder) as $file) {
            if (isset($referenced[$file])) {
                continue;
            }

            if (Storage::disk('local')->lastModified($file) < $cutoff) {
                Storage::disk('local')->delete($file);
            }
        }
    }

    /**
     * @param  array<string, true>  $knownTenantIds
     */
    private function sweepAbandonedTenantFolders(array $knownTenantIds, int $cutoff): void
    {
        foreach (Storage::disk('local')->directories('exports') as $folder) {
            if (isset($knownTenantIds[basename($folder)])) {
                continue;
            }

            foreach (Storage::disk('local')->files($folder) as $file) {
                if (Storage::disk('local')->lastModified($file) < $cutoff) {
                    Storage::disk('local')->delete($file);
                }
            }

            if (Storage::disk('local')->files($folder) === []) {
                Storage::disk('local')->deleteDirectory($folder);
            }
        }
    }
}
