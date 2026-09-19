<?php

namespace App\Jobs\System;

use App\Models\Import;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * P1 (review general) — plasă de siguranță pentru DOUĂ ferestre distincte în care un import
 * blocat oprea §22.5 (un singur import activ per tenant) la nesfârșit, fără nicio cale de
 * recuperare automată. Butonul „Cancel import" (`CancelImportAction`, `Imports/Show.tsx`)
 * rămâne calea PRINCIPALĂ; acest job e doar plasa, pentru cazul în care nimeni nu revine să
 * apese butonul.
 *
 *  1. **Lanțul de joburi auto-continue moare între chunk-uri** (`validating`/`importing`):
 *     un proces ucis abrupt (OOM, deploy) între COMMIT-ul care scrie progresul și
 *     `RPUSH`-ul `after_commit` pe Redis nu lasă niciun job de redelivrat — blocaj permanent,
 *     identic cu abandonul de mai jos. Rândul nu mai avansează (`updated_at` nu se schimbă),
 *     deci un prag pe `updated_at` îl prinde indiferent de cauză.
 *  2. **Abandon înainte de orice job** (`uploaded`/`mapped`): omul a încărcat sau mapat și
 *     n-a mai revenit — niciun job n-a existat vreodată pentru acest import.
 *
 * DOUĂ praguri, nu unul (`throughput.limits.import_stuck_minutes` / `import_abandoned_hours`,
 * motivate în comentariile din `config/throughput.php`): maparea coloanelor e activitate
 * umană, cu pauze — 15 minute acolo ar fi agresiv.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4), structurat ca
 * `FailStuckBulkOperationsJob`: fără tenant, iterează tenanții explicit, un `UPDATE` atomic
 * condiționat per tenant — niciun citește-apoi-scrie.
 */
class FailStuckImportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    private const RUNNING_STATUSES = [Import::STATUS_VALIDATING, Import::STATUS_IMPORTING];

    private const NOT_STARTED_STATUSES = [Import::STATUS_UPLOADED, Import::STATUS_MAPPED];

    public function handle(): void
    {
        $stuckCutoff = now()->subMinutes($this->stuckAfterMinutes());
        $abandonedCutoff = now()->subHours($this->abandonedAfterHours());

        Tenant::query()->eachById(function (Tenant $tenant) use ($stuckCutoff, $abandonedCutoff): void {
            TenantContext::run($tenant, function () use ($stuckCutoff, $abandonedCutoff): void {
                Import::query()
                    ->whereIn('status', self::RUNNING_STATUSES)
                    ->where('updated_at', '<', $stuckCutoff)
                    ->update(['status' => Import::STATUS_FAILED, 'completed_at' => now()]);

                Import::query()
                    ->whereIn('status', self::NOT_STARTED_STATUSES)
                    ->where('updated_at', '<', $abandonedCutoff)
                    ->update(['status' => Import::STATUS_FAILED, 'completed_at' => now()]);
            });
        });
    }

    private function stuckAfterMinutes(): int
    {
        return (int) config('throughput.limits.import_stuck_minutes');
    }

    private function abandonedAfterHours(): int
    {
        return (int) config('throughput.limits.import_abandoned_hours');
    }
}
