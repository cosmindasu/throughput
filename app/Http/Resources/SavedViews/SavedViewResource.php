<?php

namespace App\Http\Resources\SavedViews;

use App\Models\SavedView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând din `SavedViewPicker.tsx` (meniul „My views"/"Team views"). `canUpdate`/`canDelete`
 * PER RÂND — un Manager le are pe toate, un Agent doar pe ale lui (§7.4/§7.5, `SavedViewPolicy`).
 *
 * @mixin SavedView
 */
class SavedViewResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, resourceType: string, visibility: string, filter: array<string, string>, sort: string, canUpdate: bool, canDelete: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'resourceType' => $this->resource_type,
            'visibility' => $this->visibility,
            'filter' => $this->filters,
            'sort' => $this->sort,
            'canUpdate' => (bool) $request->user()?->can('update', $this->resource),
            'canDelete' => (bool) $request->user()?->can('delete', $this->resource),
        ];
    }
}
