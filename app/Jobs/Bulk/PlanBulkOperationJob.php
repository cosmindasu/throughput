<?php

namespace App\Jobs\Bulk;

use App\Models\BulkOperation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Bulk\BulkWritableResources;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Job PLANIFICATOR (§13.2, pct. 2) — job de TENANT (ADR-014, pct. 4), structurat ca
 * `App\Jobs\Exports\ExportListJob` (P1-003): DOUĂ tranzacții scurte prin
 * `TenantContext::run()`, NICIODATĂ `App\Jobs\Middleware\ApplyTenantContextToJob` — acel
 * middleware ar înfășura tot `handle()` într-o SINGURĂ tranzacție, deci `running` s-ar
 * comite odată cu starea finală, invizibil la polling-ul din `Bulk/Show.tsx`.
 *
 * Faza a doua re-rulează filtrul (sau setul explicit de id-uri) capturat la dispatch,
 * paginează pe cursor în chunk-uri (`chunkById` — keyset pagination pe cheia primară ULID,
 * niciodată OFFSET) și grupează joburile de chunk într-un `Bus::batch()` cu `allowFailures()`
 * (BR-BULK-01: un chunk eșuat nu oprește restul).
 */
class PlanBulkOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
    ) {}

    public function handle(): void
    {
        $found = TenantContext::run($this->tenantId, function (): bool {
            $operation = BulkOperation::query()->find($this->bulkOperationId);

            // Rândul poate lipsi dacă operația a fost curățată concurent, sau nu mai e
            // `pending` (reluare a jobului după un timeout — nu re-planifica peste un batch
            // deja creat).
            if ($operation === null || $operation->status !== BulkOperation::STATUS_PENDING) {
                return false;
            }

            $operation->update(['status' => BulkOperation::STATUS_RUNNING]);

            return true;
        });

        if (! $found) {
            return;
        }

        TenantContext::run($this->tenantId, function (): void {
            $operation = BulkOperation::query()->find($this->bulkOperationId);

            if ($operation === null) {
                return;
            }

            try {
                $this->plan($operation);
            } catch (Throwable $e) {
                $operation->update([
                    'status' => BulkOperation::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                report($e);
            }
        });
    }

    private function plan(BulkOperation $operation): void
    {
        $snapshot = $operation->filter_snapshot ?? [];
        $resource = BulkWritableResources::resolve($operation->resource_type);

        $actorId = $snapshot['actor_id'] ?? null;
        $actor = $actorId !== null ? User::query()->find($actorId) : null;

        if ($actor === null) {
            $operation->update([
                'status' => BulkOperation::STATUS_FAILED,
                'error_message' => 'The member who started this operation is no longer available.',
            ]);

            return;
        }

        $query = $this->queryFor($resource, $snapshot, $actor);

        if ($snapshot['restrict_to_own_records'] ?? false) {
            $resource->scopeToOwnRecords($query, $actor);
        }

        // Fără `with()` la nivelul ăsta: chunk-ul are nevoie doar de cheia primară, iar
        // eager-load-urile din `baseQuery()` (ex: `owner:id,name` pe `AccountList`) ar
        // declanșa o interogare de relație în plus, per chunk, complet inutilă aici.
        $query = $query->without(array_keys($query->getEagerLoads()));

        $keyName = $query->getModel()->getKeyName();
        $query->reorder()->orderBy($keyName);

        $chunkSize = (int) config('throughput.limits.bulk_chunk_size');
        $tenantId = $this->tenantId;
        $operationId = $operation->getKey();
        $resourceType = $operation->resource_type;
        $action = $operation->action;
        $payload = $snapshot['action_payload'] ?? [];

        /** @var list<ProcessBulkChunkJob> $jobs */
        $jobs = [];

        $query->chunkById($chunkSize, function ($rows) use (&$jobs, $keyName, $tenantId, $operationId, $resourceType, $action, $payload): void {
            $jobs[] = new ProcessBulkChunkJob(
                $tenantId,
                $operationId,
                $resourceType,
                $action,
                $rows->pluck($keyName)->map(static fn ($id): string => (string) $id)->all(),
                $payload,
            );
        }, $keyName);

        // P1-001 (code review) — fereastra dintre scrierea „running" (prima tranzacție a
        // lui `handle()`, deja comisă) și `Bus::batch()->dispatch()`, mai jos:
        // `BulkOperationController::cancel()` poate anula o operație aflată exact aici
        // (fără `batch_id` încă scris), altfel ignorată — batch-ul tot pornea. Reverificare
        // CHIAR ÎNAINTE de dispatch, fără nicio scriere de bază de date între citire și
        // `->dispatch()`: o anulare care s-a COMIS deja e vizibilă aici (Postgres READ
        // COMMITTED — fiecare instrucțiune nouă vede ce s-a comis între timp, chiar în
        // interiorul aceleiași tranzacții deschise de `TenantContext::run()`). Verificarea
        // stă ÎNAINTEA ramurii „`$jobs` gol" de mai jos, ca o operație anulată cu 0 rânduri
        // de procesat să nu fie rescrisă tăcut pe `completed`.
        if ($operation->fresh()?->status !== BulkOperation::STATUS_RUNNING) {
            return;
        }

        if ($jobs === []) {
            $operation->update(['status' => BulkOperation::STATUS_COMPLETED, 'total_rows' => 0]);

            return;
        }

        $batch = Bus::batch($jobs)
            ->name('bulk:'.$operationId)
            ->allowFailures()
            ->onQueue('bulk')
            ->finally(function (Batch $batch) use ($tenantId, $operationId): void {
                // NU se scrie direct aici: closure-urile de batch sunt joburi (`CallQueuedClosure`)
                // serializate INDEPENDENT de `App\Jobs\Middleware\ApplyTenantContextToJob` — n-au
                // context de tenant. Se dispecerizează un job de tenant PROPRIU, cu `tenantId`
                // scalar explicit (ADR-014), care restaurează contextul singur.
                FinalizeBulkOperationJob::dispatch($tenantId, $operationId)->onQueue('bulk');
            })
            ->dispatch();

        $operation->update(['batch_id' => $batch->id]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function queryFor(BulkWritableResource $resource, array $snapshot, User $actor): Builder
    {
        $ids = $snapshot['ids'] ?? null;

        if ($ids !== null) {
            $query = $resource->newQuery();

            return $query->whereIn($query->getModel()->getKeyName(), $ids);
        }

        $list = app($resource->listClass());
        $listQuery = $list->fromState($snapshot);

        return $list->query($listQuery, $actor);
    }
}
