<?php

namespace App\Http\Resources\Bulk;

use App\Models\BulkOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Bus;

/**
 * `Bulk/Show` — pagina de status a unei operații în masă de SCRIERE (§13.2), mirror-ul lui
 * `App\Http\Resources\Exports\ExportResource` pentru latura de scriere. `totalJobs`/
 * `processedJobs`/`failedJobs` (plan §9, „progres processedJobs/totalJobs") vin din
 * `job_batches`, citit prin `Bus::findBatch()` cât timp rândul mai există acolo —
 * `queue:prune-batches` îl șterge periodic, moment din care rămâne doar `status`, deja
 * scris terminal de `App\Jobs\Bulk\FinalizeBulkOperationJob`.
 *
 * `processedRowsEstimate` e o APROXIMARE deliberată, nu un contor exact: schema
 * `bulk_operations` (Faza 1) n-are o coloană de rânduri procesate, iar chunk-urile nu
 * raportează individual câte rânduri au atins — vezi raportul pachetului. Cu chunk-uri de
 * mărime egală (mai puțin ultimul), `total_rows × processedJobs / totalJobs` e exactă la
 * 0% și 100%, corectă la limită oriunde altundeva.
 *
 * @mixin BulkOperation
 */
class BulkOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $batch = $this->batch_id !== null ? Bus::findBatch($this->batch_id) : null;
        $totalJobs = $batch?->totalJobs ?? 0;
        $processedJobs = $batch?->processedJobs() ?? 0;

        return [
            'id' => $this->id,
            'resourceType' => $this->resource_type,
            'action' => $this->action,
            'status' => $this->status,
            'totalRows' => $this->total_rows,
            'totalJobs' => $totalJobs,
            'processedJobs' => $processedJobs,
            'failedJobs' => $batch?->failedJobs ?? 0,
            'processedRowsEstimate' => $this->processedRowsEstimate($totalJobs, $processedJobs),
            'canCancel' => (bool) $request->user()?->can('cancel', $this->resource),
            'errorMessage' => $this->error_message,
        ];
    }

    private function processedRowsEstimate(int $totalJobs, int $processedJobs): int
    {
        if ($this->status === BulkOperation::STATUS_COMPLETED) {
            return $this->total_rows;
        }

        if ($totalJobs === 0) {
            return 0;
        }

        return (int) round($this->total_rows * $processedJobs / $totalJobs);
    }
}
