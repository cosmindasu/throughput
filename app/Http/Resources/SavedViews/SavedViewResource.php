<?php

namespace App\Http\Resources\SavedViews;

use App\Models\SavedView;
use App\Support\SavedViews\ListColumns;
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
     * @return array{id: string, name: string, resourceType: string, visibility: string, filter: array<string, string>, sort: string, columns: list<string>, canUpdate: bool, canDelete: bool}
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
            // Selector de coloane (§15.1) — sanitizate față de lista PERMISĂ curentă, nu
            // valoarea brută stocată: o vedere veche cu chei devenite invalide nu trebuie
            // să arate „activă" o combinație pe care selectorul n-ar mai produce-o azi.
            'columns' => ListColumns::fromState($this->columns, $this->resource_type),
            'canUpdate' => (bool) $request->user()?->can('update', $this->resource),
            'canDelete' => (bool) $request->user()?->can('delete', $this->resource),
        ];
    }
}
