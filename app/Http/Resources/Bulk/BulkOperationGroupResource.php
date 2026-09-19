<?php

namespace App\Http\Resources\Bulk;

use App\Models\BulkOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * BR-BULK-04 — progresul AGREGAT al unui `group_id` (US-TEN-03: reatribuirea la
 * dezactivarea unui membru, trei rânduri `bulk_operations` — conturi, deals, comenzi —
 * legate prin același `group_id`). Mirror-ul de grup al `BulkOperationResource`, pe care
 * îl reutilizează per operație (`operations`), nu-l duplică.
 *
 * @mixin Collection<int, BulkOperation>
 */
final class BulkOperationGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, BulkOperation> $operations */
        $operations = $this->resource;

        return [
            'groupId' => (string) $operations->first()?->group_id,
            'status' => $this->aggregateStatus($operations),
            'totalRows' => (int) $operations->sum('total_rows'),
            'operations' => BulkOperationResource::collection($operations)->resolve($request),
            'canCancel' => $this->canCancel($operations, $request),
        ];
    }

    /**
     * Precedență: o singură operație încă `pending`/`running` ține tot grupul „running" —
     * utilizatorul vede o operație în desfășurare, nu trei bare separate care termină la
     * momente diferite. Abia când NIMIC nu mai rulează se uită la eșecuri/anulări.
     *
     * @param  Collection<int, BulkOperation>  $operations
     */
    private function aggregateStatus(Collection $operations): string
    {
        if ($operations->contains(fn (BulkOperation $op) => in_array($op->status, [
            BulkOperation::STATUS_PENDING, BulkOperation::STATUS_RUNNING,
        ], true))) {
            return BulkOperation::STATUS_RUNNING;
        }

        if ($operations->contains(fn (BulkOperation $op) => $op->status === BulkOperation::STATUS_FAILED)) {
            return BulkOperation::STATUS_FAILED;
        }

        if ($operations->isNotEmpty() && $operations->every(fn (BulkOperation $op) => $op->status === BulkOperation::STATUS_CANCELLED)) {
            return BulkOperation::STATUS_CANCELLED;
        }

        return BulkOperation::STATUS_COMPLETED;
    }

    /**
     * @param  Collection<int, BulkOperation>  $operations
     */
    private function canCancel(Collection $operations, Request $request): bool
    {
        if ($this->aggregateStatus($operations) !== BulkOperation::STATUS_RUNNING) {
            return false;
        }

        return $operations->isNotEmpty() && $operations->every(
            fn (BulkOperation $op) => (bool) $request->user()?->can('cancel', $op)
        );
    }
}
