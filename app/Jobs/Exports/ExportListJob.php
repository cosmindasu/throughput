<?php

namespace App\Jobs\Exports;

use App\Models\BulkOperation;
use App\Services\Tenancy\TenantContext;
use App\Support\Exports\CsvExporter;
use App\Support\Exports\ExportableResources;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * US-CRM-03, §13.2 — export peste pragul sincron. Job de TENANT (ADR-014, pct. 4):
 * constructorul primește scalari (`tenantId`, `bulkOperationId`), niciodată modelele —
 * `BulkOperation` s-ar reîncărca fără context la deserializare (capcana din §6.3).
 *
 * Generic peste `ExportableResources`: nu știe dacă exportă conturi sau, mai târziu,
 * contacte — doar `resource_type` de pe `bulk_operations`.
 *
 * P1-003 — FĂRĂ `middleware(): [new ApplyTenantContextToJob]`. Acel middleware ar înfășura
 * tot `handle()` într-o SINGURĂ tranzacție, deci `running` s-ar comite odată cu starea
 * finală (`completed`/`failed`) — niciodată vizibil la polling-ul din `Exports/Show.tsx`,
 * care rulează pe altă cerere/tranzacție. Remediere ca în ADR-014, pct. 5 (joburile cu
 * mai multe faze): DOUĂ tranzacții scurte prin `TenantContext::run()`, apelat direct aici,
 * fără middleware-ul de job. Prima marchează `running` și se comite; a doua face munca și
 * scrie starea terminală. Jobul rămâne idempotent pe `bulkOperationId`: fiecare fază
 * repornește dintr-un `find()` fresh, deci o reluare (retry) nu dublează nimic.
 */
class ExportListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
    ) {}

    public function handle(): void
    {
        $found = TenantContext::run($this->tenantId, function (): bool {
            $operation = BulkOperation::query()->find($this->bulkOperationId);

            // Rândul poate lipsi dacă operația a fost anulată/curățată concurent — jobul
            // nu are ce raporta unde, deci se oprește liniștit, nu eșuează zgomotos.
            if ($operation === null) {
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
                $list = ExportableResources::resolve($operation->resource_type);
                $listQuery = $list->fromState($operation->filter_snapshot ?? []);
                $query = $list->query($listQuery, $operation->user);

                $path = "exports/{$this->tenantId}/{$operation->getKey()}.csv";

                Storage::disk('local')->put($path, CsvExporter::toString($list, $query));

                $operation->update([
                    'status' => BulkOperation::STATUS_COMPLETED,
                    'result_path' => $path,
                ]);
            } catch (Throwable $e) {
                $operation->update([
                    'status' => BulkOperation::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                report($e);
            }
        });
    }
}
