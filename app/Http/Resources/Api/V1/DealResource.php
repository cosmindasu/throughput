<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul public al unei oportunități (specs.md §18).
 *
 * `stage` e expus ca ID + nume, nu ca etichetă tradusă: etapele sunt DATE ALE
 * TENANTULUI (§9.1), definite de el în Settings → Pipeline, deci un consumator care
 * mapează pe nume trebuie să vadă exact numele configurat, nu unul normalizat de noi.
 *
 * @mixin Deal
 */
final class DealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'accountId' => $this->account_id,
            'primaryContactId' => $this->primary_contact_id,
            'pipelineId' => $this->pipeline_id,
            'stageId' => $this->stage_id,
            'stageName' => $this->whenLoaded('stage', fn () => $this->stage?->name),
            'status' => $this->status,
            'value' => (float) $this->value,
            'currency' => $this->currency,
            'expectedCloseDate' => $this->expected_close_date?->toDateString(),
            'lostReason' => $this->lost_reason,
            'ownerUserId' => $this->owner_user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
