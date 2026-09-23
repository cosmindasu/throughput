<?php

namespace App\Http\Resources\Exports;

use App\Models\BulkOperation;
use App\Support\JobErrorMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Exports/Show` — pagina de status a unei operații de export (§13.2). `canDownload` e
 * calculat aici, nu doar în `can` de pagină, ca link-ul de descărcare să dispară exact
 * când `exports.download` ar refuza (autor greșit sau fișier încă absent).
 *
 * @mixin BulkOperation
 */
class ExportResource extends JsonResource
{
    /**
     * @return array{id: string, resourceType: string, format: string, status: string, totalRows: int, canDownload: bool, expiresAt: string|null, isExpired: bool, errorMessage: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resourceType' => $this->resource_type,
            // §13.5 (decizie DomPDF) — implicit `csv`: rândurile scrise înainte de acest
            // câmp (Accounts/Contacts, valul 1) nu-l au deloc în `filter_snapshot`.
            // `Exports/Show.tsx` citește asta pentru eticheta butonului de descărcare.
            'format' => $this->filter_snapshot['format'] ?? 'csv',
            'status' => $this->status,
            'totalRows' => $this->total_rows,
            'canDownload' => (bool) $request->user()?->can('download', $this->resource),
            // FR-GDPR-01 (§20.5) — `Exports/Show.tsx` arată data de expirare cât linkul e
            // valabil, apoi „This export expired on …" în locul butonului de descărcare.
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'isExpired' => $this->isExpired(),
            // I18N-03 — motivul eșecului, tradus în limba acestei cereri. Până la 2026-09-23
            // pagina arăta doar „failed", deși `ExportListJob` scria deja un motiv acționabil
            // (ex. plafonul XLSX/PDF depășit — „folosiți CSV").
            'errorMessage' => JobErrorMessage::render($this->error_message),
        ];
    }
}
