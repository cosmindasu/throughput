<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract implementat de o `ResourceList` care poate fi exportată CSV (US-CRM-03, §13.2).
 *
 * Separată de `ResourceList` (fundația comună tuturor listelor, nu doar celor
 * exportabile) — o listă fără reprezentare tabulară stabilă (ex: un kanban) nu are de ce
 * să declare un antet CSV. `AccountList` e prima implementare; `ExportListJob` și
 * exportul sincron lucrează doar prin acest contract, deci exportul de contacte (Faza 2,
 * alt pachet) devine o singură clasă nouă + o linie în `ExportableResources`, nu o
 * reimplementare a mecanismului.
 */
interface ExportableList
{
    /** @return list<string> */
    public function exportHeaders(): array;

    /** @return list<string|int|float|null> */
    public function exportRow(Model $row): array;
}
