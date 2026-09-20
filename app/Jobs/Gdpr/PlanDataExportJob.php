<?php

namespace App\Jobs\Gdpr;

use App\Actions\Gdpr\DataExportSources;
use App\Models\DataExportRequest;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * FR-GDPR-01, plan §11 — planificatorul exportului GDPR. Job de TENANT (ADR-014, pct. 4):
 * constructorul primește scalari, niciodată modelul.
 *
 * Structurat EXACT ca `App\Jobs\Bulk\PlanBulkOperationJob` (plan §11: „același pattern ca
 * la operațiile în masă din Faza 3"), inclusiv motivele:
 *
 *  - **Două tranzacții scurte** prin `TenantContext::run()`, niciodată
 *    `App\Jobs\Middleware\ApplyTenantContextToJob`: acel middleware ar înfășura tot
 *    `handle()` într-o singură tranzacție, deci `processing` s-ar comite odată cu starea
 *    finală și n-ar fi vizibil niciodată la polling-ul din `Settings/DataExport/Index.tsx`.
 *  - **`finally()` dispecerizează un job de tenant propriu**, nu scrie direct: closure-urile
 *    de batch sunt serializate ca `CallQueuedClosure`, fără niciun context de tenant.
 *
 * O diferență deliberată față de operațiile în masă: **fără `allowFailures()`**. BR-BULK-01
 * cere explicit ca un chunk eșuat să nu oprească restul, fiindcă fiecare chunk e muncă
 * independentă, deja aplicată. Aici e invers — o arhivă căreia îi lipsește `invoices.json`
 * e un răspuns GREȘIT la o cerere de portabilitate, nu unul parțial. Prima entitate care
 * eșuează anulează batch-ul, iar `finally()` marchează cererea `failed`, fără niciun fișier
 * livrat.
 *
 * `$tries = 1`, ca la planificatorul de operații în masă: o reluare ar putea crea un al
 * doilea batch peste unul deja pornit. Reluarea sigură e ca omul să ceară un export nou —
 * ieftin, fiindcă nimic nu s-a livrat.
 */
class PlanDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $tenantId,
        public string $dataExportRequestId,
    ) {}

    public function handle(): void
    {
        $claimed = TenantContext::run($this->tenantId, function (): bool {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            // Rândul poate lipsi (reset de demo, tenant șters) sau poate fi deja preluat de
            // o livrare duplicată a aceluiași job — în ambele cazuri jobul se oprește
            // liniștit, nu eșuează zgomotos.
            if ($export === null || $export->status !== DataExportRequest::STATUS_QUEUED) {
                return false;
            }

            $export->update(['status' => DataExportRequest::STATUS_PROCESSING]);

            return true;
        });

        if (! $claimed) {
            return;
        }

        $tenantId = $this->tenantId;
        $requestId = $this->dataExportRequestId;

        $jobs = array_map(
            static fn (string $name): ExportTenantEntityJob => new ExportTenantEntityJob($tenantId, $requestId, $name),
            DataExportSources::names(),
        );

        try {
            Bus::batch($jobs)
                ->name('gdpr-export:'.$requestId)
                ->onQueue('bulk')
                ->finally(function (Batch $batch) use ($tenantId, $requestId): void {
                    FinalizeDataExportJob::dispatch($tenantId, $requestId, $batch->id)->onQueue('bulk');
                })
                ->dispatch();
        } catch (Throwable $e) {
            $this->markFailed('The export could not be started. Try again.');

            report($e);
        }
    }

    /**
     * Plasă de siguranță pentru eșecurile care NU trec prin `catch`-ul de mai sus — un
     * proces ucis de OOM sau de `pcntl_alarm` moare fără să ajungă în niciun bloc propriu.
     * Fără ea, cererea ar rămâne `processing` la nesfârșit, iar ecranul ar face polling fără
     * sfârșit (stările terminale sunt `completed`/`failed`).
     */
    public function failed(Throwable $e): void
    {
        $this->markFailed('The export could not be started. Try again.');

        report($e);
    }

    private function markFailed(string $message): void
    {
        TenantContext::run($this->tenantId, function () use ($message): void {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            if ($export === null || $export->status === DataExportRequest::STATUS_COMPLETED) {
                return;
            }

            $export->update([
                'status' => DataExportRequest::STATUS_FAILED,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
        });
    }
}
