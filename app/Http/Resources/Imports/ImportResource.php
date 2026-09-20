<?php

namespace App\Http\Resources\Imports;

use App\Models\Import;
use App\Support\Imports\ImportableResources;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare din lista de importuri (Imports/Index) — și, îmbogățită cu `columnMapping`, baza
 * ecranului Imports/Show. `createdBy` vine din `whenLoaded()`: `ImportController::index()`
 * face `with('createdBy:id,name')`, ca lista să nu N+1 pe fiecare rând.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resourceType' => $this->resource_type,
            'resourceLabel' => ImportableResources::resolve($this->resource_type)->label(),
            'originalFilename' => $this->original_filename,
            'status' => $this->status,
            'totalRows' => $this->total_rows,
            'validRows' => $this->valid_rows,
            'errorRows' => $this->error_rows,
            'columnMapping' => $this->column_mapping,
            // FR-TEN-04 — „orice referință de owner/creator/actor către un membru
            // dezactivat" include și autorul unui import: lista de importuri e istoric, iar
            // un nume care se schimbă tăcut (sau dispare) e exact ce cerința interzice.
            // Aplicat AICI, în `Resource` (plan §11, specs.md §1.2 regula 1), nu în TSX.
            'createdBy' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => DeactivatedMemberNames::label($this->createdBy->name, $this->createdBy->id),
            ]),
            'createdAt' => $this->created_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
        ];
    }
}
