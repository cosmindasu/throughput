<?php

namespace App\Http\Resources\Imports;

use App\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând invalid al probei uscate (Imports/Show, tabelul de erori) — „row 47, column
 * Price: non-numeric value" (US-IMP-01): `rowNumber` e numărul FIZIC din fișierul original
 * (`ImportRowsRangeReader`), nu un index relativ la pagina curentă.
 *
 * FĂRĂ `raw_data` (P3, review general) — `InvalidRowsTable` (`Imports/Show.tsx`) nu-l
 * randează niciodată; conținutul brut al rândului rămâne disponibil DOAR prin raportul CSV
 * descărcabil (`ImportErrorReportBuilder`), nu trimis inutil la fiecare randare a paginii.
 *
 * @mixin ImportRow
 */
class ImportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rowNumber' => $this->row_number,
            'errors' => $this->errors ?? [],
        ];
    }
}
