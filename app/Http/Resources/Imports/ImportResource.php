<?php

namespace App\Http\Resources\Imports;

use App\Models\Import;
use App\Support\Imports\ImportableResources;
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
            'createdBy' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'createdAt' => $this->created_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
        ];
    }
}
