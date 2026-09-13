<?php

namespace App\Http\Resources\Exports;

use App\Models\BulkOperation;
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
     * @return array{id: string, resourceType: string, status: string, totalRows: int, canDownload: bool, expiresAt: string|null, isExpired: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resourceType' => $this->resource_type,
            'status' => $this->status,
            'totalRows' => $this->total_rows,
            'canDownload' => (bool) $request->user()?->can('download', $this->resource),
            // FR-GDPR-01 (§20.5) — `Exports/Show.tsx` arată data de expirare cât linkul e
            // valabil, apoi „This export expired on …" în locul butonului de descărcare.
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'isExpired' => $this->isExpired(),
        ];
    }
}
