<?php

namespace App\Jobs\Bulk;

use App\Models\BulkOperation;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * `finally()` al batch-ului (§13.2, pct. 6-7) — job de TENANT PROPRIU (ADR-014), NU o
 * closure care ar scrie direct în `bulk_operations`: closure-urile pasate la
 * `Bus::batch()->finally()` sunt serializate ca joburi (`CallQueuedClosure`) INDEPENDENT de
 * `App\Jobs\Middleware\ApplyTenantContextToJob` — n-ar avea niciun context de tenant dacă ar
 * atinge baza de date direct. `PlanBulkOperationJob::plan()` dispecerizează AICI, cu
 * `tenantId` scalar explicit, exact ca orice alt job de tenant.
 *
 * De ce se scrie o stare TERMINALĂ persistentă, în loc s-o deducem mereu din
 * `Bus::findBatch()`: `queue:prune-batches` (§13.2, pct. 8, `routes/console.php`) șterge
 * periodic rândurile din `job_batches`. Fără o scriere aici, un `bulk_operations` a cărui
 * batch a fost curățată ar rămâne etern „running" — rândul din `bulk_operations` e sursa de
 * adevăr pentru starea FINALĂ; `Bus::findBatch()` (în `BulkOperationResource`) rămâne doar
 * pentru progresul LIVE, cât batch-ul mai există.
 *
 * BR-BULK-01: `allowFailures()` înseamnă că unele chunk-uri pot eșua fără să oprească restul
 * — o operație cu eșecuri parțiale tot se termină ca `completed` (cu `failedJobs` > 0,
 * raportat separat), nu ca `failed`. `failed` rămâne rezervat erorilor de PLANIFICARE
 * (`PlanBulkOperationJob::handle()`), unde operația întreagă n-a apucat să înceapă.
 */
class FinalizeBulkOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $bulkOperationId,
    ) {}

    public function handle(): void
    {
        TenantContext::run($this->tenantId, function (): void {
            $operation = BulkOperation::query()->find($this->bulkOperationId);

            if ($operation === null || $operation->batch_id === null) {
                return;
            }

            $batch = Bus::findBatch($operation->batch_id);

            if ($batch === null) {
                return;
            }

            $operation->update([
                'status' => $batch->cancelled() ? BulkOperation::STATUS_CANCELLED : BulkOperation::STATUS_COMPLETED,
            ]);
        });
    }
}
