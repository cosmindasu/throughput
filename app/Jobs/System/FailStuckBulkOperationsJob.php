<?php

namespace App\Jobs\System;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\JobErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * §13.2 — plasă de siguranță OPERAȚIONALĂ pentru o fereastră găsită la review (preexistentă
 * din valul 1): dacă procesul moare (OOM real pe VPS-ul comun) exact între
 * `Bus::batch(...)->dispatch()` și `$operation->update(['batch_id' => ...])`, din
 * `PlanBulkOperationJob::plan()`, tranzacția care ar fi scris `batch_id` face rollback —
 * `bulk_operations` rămâne `pending`/`running` cu `batch_id = NULL`, la nesfârșit.
 * `PlanBulkOperationJob` are DELIBERAT `tries = 1` (P1-001, code review — „nu re-planifica
 * peste un batch deja creat"), deci nu se reîncearcă singur și nu ajunge niciodată la
 * `failed()`: fără acest job, singura ieșire era „Cancel" manual pe pagina de progres.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4), structurat ca
 * `PruneExpiredExportsJob`: fără tenant, iterează tenanții explicit, cu o tranzacție și un
 * context per tenant.
 *
 * Un singur `UPDATE` condiționat, per tenant — ACEEAȘI disciplină ca
 * `BulkOperationController::cancel()`: `status IN (pending, running)` ȘI `batch_id IS NULL`
 * ȘI `updated_at` mai vechi decât pragul. O operație care își primește `batch_id` chiar în
 * fereastra asta nu e atinsă — sub READ COMMITTED, Postgres reevaluează `WHERE`-ul unui
 * `UPDATE` aflat în conflict cu o altă tranzacție abia după ce aceea comite (EvalPlanQual),
 * deci un rând al cărui `batch_id` tocmai s-a scris nu mai potrivește `whereNull('batch_id')`
 * — nicio cursă posibilă, fără SELECT-apoi-UPDATE separat.
 *
 * DOAR acțiunile de SCRIERE (`BulkChunkActions::isRegistered()` — au `batch_id` prin
 * design, spre deosebire de exporturi, tratate mai jos, în docblock-ul clasei, nu în cod:
 * `ExportListJob` are deja `$tries = 3` ȘI `failed()` (code review P1, fixul anterior din
 * acest pachet) — un export blocat între „running" și restul jobului e redelivrat de coadă
 * (`retry_after`) și, dacă tot eșuează, ajunge la `failed()` SINGUR, într-un worker sănătos.
 * `PlanBulkOperationJob` n-are acest plasă (tries=1, deliberat), deci doar operațiile de
 * scriere au nevoie de un sweeper extern ca acesta.
 */
class FailStuckBulkOperationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    // I18N-03 — cheie de catalog, nu text (`JobErrorMessage`); tradusă abia la randare
    // (`BulkOperationResource`), în locale-ul cererii, nu al acestui job de sistem
    // (`.ai/rules/tenancy.md`, „joburi de sistem" — n-are niciun `locale` de utilizator).
    private const MESSAGE_KEY = 'job_errors.bulk.stuck_operation';

    public function handle(): void
    {
        $cutoff = now()->subMinutes($this->staleAfterMinutes());
        $writeActions = array_keys(BulkChunkActions::map());

        Tenant::query()->eachById(function (Tenant $tenant) use ($cutoff, $writeActions): void {
            TenantContext::run($tenant, function () use ($cutoff, $writeActions): void {
                BulkOperation::query()
                    ->whereIn('action', $writeActions)
                    ->whereIn('status', [BulkOperation::STATUS_PENDING, BulkOperation::STATUS_RUNNING])
                    ->whereNull('batch_id')
                    ->where('updated_at', '<', $cutoff)
                    ->update([
                        'status' => BulkOperation::STATUS_FAILED,
                        'error_message' => JobErrorMessage::encode(self::MESSAGE_KEY),
                    ]);
            });
        });
    }

    private function staleAfterMinutes(): int
    {
        return (int) config('throughput.limits.bulk_stuck_operation_minutes');
    }
}
