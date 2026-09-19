<?php

namespace App\Http\Resources\Reports;

use App\Models\ReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând din istoricul de rulări (FR-REP-01) — status, când, câte rânduri, mesajul de
 * eroare. Descărcarea NU se decide per rulare aici: dreptul de a descărca fișierele unui
 * raport e per DEFINIȚIE (`ReportDefinitionPolicy::download()`), constant pentru toate
 * rulările ei — calculat O SINGURĂ dată în pagina părinte (`can.download`,
 * `Reports/Show.tsx`), nu repetat pe fiecare rând (ar cere reîncărcarea definiției din
 * fiecare `ReportRun`, doar ca să răspundă la o întrebare care nu variază între rulări).
 * `hasFile` spune doar dacă ESTE ceva de descărcat pentru acest rând anume.
 *
 * @mixin ReportRun
 */
class ReportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'triggeredBy' => $this->triggered_by,
            // Fără `created_at` (append-only fără timestamps, vezi App\Models\ReportRun) —
            // `startedAt` e `null` cât timp rularea e `queued`; frontend-ul arată „Queued"
            // în locul unei date în acel caz, nu un gol.
            'startedAt' => $this->started_at?->toIso8601String(),
            'finishedAt' => $this->finished_at?->toIso8601String(),
            'rowCount' => $this->row_count,
            'errorMessage' => $this->error_message,
            'hasFile' => $this->status === 'success' && $this->file_path !== null,
        ];
    }
}
