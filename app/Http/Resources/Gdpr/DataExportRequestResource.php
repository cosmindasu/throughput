<?php

namespace App\Http\Resources\Gdpr;

use App\Models\DataExportRequest;
use App\Support\JobErrorMessage;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul de props pentru `Settings/DataExport/Index` (FR-GDPR-02, specs.md §20.5).
 * Mirror manual în `resources/js/types/generated.d.ts` (`DataExportRequestRow`) — plan §1.2
 * regula 5; fișierul acela e al integratorului, deci blocul exact e în raportul lotului.
 *
 * `canDownload` se calculează SERVER-SIDE, din Policy, nu din `status` în React (§1.2
 * regula 1 + FR-RBAC-01): butonul de descărcare trebuie să lipsească exact când ruta ar
 * răspunde 403 — inclusiv pentru un export care aparține altui Owner.
 *
 * @mixin DataExportRequest
 */
final class DataExportRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'requestedAt' => $this->requested_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'expiresAt' => $this->expires_at?->toISOString(),
            'isExpired' => $this->isExpired(),
            // I18N-03 — cheie codificată (`App\Jobs\Gdpr\{PlanDataExportJob,
            // ExportTenantEntityJob,FinalizeDataExportJob}`), tradusă abia aici, în
            // locale-ul cererii curente — vezi docblock-ul `JobErrorMessage`.
            'errorMessage' => JobErrorMessage::render($this->error_message),
            // FR-TEN-04 — placeholder „(deactivated)" pe autorul cererii: istoricul de
            // exporturi e chiar locul unde apare un Owner care între timp a plecat.
            'requestedBy' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => DeactivatedMemberNames::label($this->requestedBy->name, $this->requestedBy->id),
            ] : null),
            'canDownload' => $user !== null && $user->can('download', $this->resource),
        ];
    }
}
