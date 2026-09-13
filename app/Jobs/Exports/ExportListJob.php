<?php

namespace App\Jobs\Exports;

use App\Jobs\Middleware\ApplyTenantContextToJob;
use App\Models\BulkOperation;
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
 */
class ExportListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ApplyTenantContextToJob];
    }

    public function handle(): void
    {
        $operation = BulkOperation::query()->find($this->bulkOperationId);

        // Rândul poate lipsi dacă operația a fost anulată/curățată concurent — jobul nu
        // are ce raporta unde, deci se oprește liniștit, nu eșuează zgomotos.
        if ($operation === null) {
            return;
        }

        $operation->update(['status' => BulkOperation::STATUS_RUNNING]);

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
    }
}
