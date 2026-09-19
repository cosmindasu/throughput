<?php

namespace App\Jobs\Exports;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Exports\CsvExporter;
use App\Support\Exports\ExportableResources;
use App\Support\Exports\PdfExporter;
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
 *
 * `$tries`/`$timeout` FINITE (code review P1) — un job ucis abrupt (OOM real pe VPS-ul
 * comun, sau timeout via `pcntl_alarm`) nu ajunge în niciun `catch` de mai jos: procesul
 * moare, punct. Fără o limită, coada l-ar redelivra la nesfârșit (`retry_after`), operația
 * ar rămâne `running` pentru totdeauna, iar `Exports/Show.tsx` ar face polling fără sfârșit.
 * `failed()`, mai jos, e plasa de siguranță — Laravel o cheamă dintr-un WORKER SĂNĂTOS, după
 * ce `$tries` s-au epuizat.
 */
class ExportListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

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
                // §13.5 (decizie DomPDF) — implicit `csv`: rândurile scrise înaintea acestui
                // câmp (Accounts/Contacts, valul 1) nu-l au deloc în `filter_snapshot`.
                $format = $operation->filter_snapshot['format'] ?? 'csv';

                // P1 (code review) — plafonul PDF se verifică la DECLANȘARE (`ListExport`),
                // dar setul poate crește între declanșare și execuție (rânduri noi create
                // în intervalul dintre cele două). Re-numărat aici, simetric cu refuzul din
                // `ListExport`, ÎNAINTE de randare — o operație care depășește plafonul la
                // execuție se marchează `failed`, cu mesaj explicit, fără niciun fișier.
                if ($format === 'pdf') {
                    $pdfCap = (int) config('throughput.limits.export_pdf_max_rows');
                    $currentTotal = (clone $query)->toBase()->getCountForPagination();

                    if ($currentTotal > $pdfCap) {
                        $operation->update([
                            'status' => BulkOperation::STATUS_FAILED,
                            'error_message' => "This export now has {$currentTotal} rows; PDF export is capped at {$pdfCap}. Use CSV for larger exports.",
                        ]);

                        return;
                    }
                }

                $path = "exports/{$this->tenantId}/{$operation->getKey()}.{$format}";

                if ($format === 'pdf') {
                    $workspaceName = Tenant::query()->find($this->tenantId)?->name ?? 'Workspace';

                    PdfExporter::save($list, $query, $path, $workspaceName, $listQuery->toArray()['filter'] ?? []);
                } else {
                    Storage::disk('local')->put($path, CsvExporter::toString($list, $query));
                }

                $operation->update([
                    'status' => BulkOperation::STATUS_COMPLETED,
                    'result_path' => $path,
                    // FR-GDPR-01 (specs.md §20.5), retenția din throughput.limits — link de
                    // descărcare valabil un număr fix de zile; `PruneExpiredExportsJob` golește
                    // `result_path` și șterge fișierul peste acest prag (plan §7.2).
                    'expires_at' => now()->addDays((int) config('throughput.limits.export_retention_days')),
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

    /**
     * Plasă de siguranță (code review P1) — pentru eșecuri care NU trec prin `catch`-ul de
     * mai sus: un job ucis abrupt (OOM, sau timeout prin `pcntl_alarm`) moare fără să
     * ajungă în niciun bloc `catch` propriu. Coada îl redelivrează (`retry_after`) până la
     * epuizarea `$tries`, apoi Laravel cheamă `failed()` AICI, într-un worker sănătos.
     * Fără asta, operația ar rămâne `running` la infinit, iar `Exports/Show.tsx` ar face
     * polling fără sfârșit (stările terminale sunt deja `completed`/`failed`/`cancelled`,
     * verificat în `TERMINAL_STATUSES`).
     */
    public function failed(Throwable $e): void
    {
        TenantContext::run($this->tenantId, function (): void {
            $operation = BulkOperation::query()->find($this->bulkOperationId);

            if ($operation === null || $operation->status === BulkOperation::STATUS_COMPLETED) {
                return;
            }

            $operation->update([
                'status' => BulkOperation::STATUS_FAILED,
                'error_message' => 'This export could not be completed. Try again from the list.',
                'result_path' => null,
            ]);
        });

        report($e);
    }
}
