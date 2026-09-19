<?php

namespace App\Http\Resources;

use App\Models\Deal;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Un rând din `Deals/Index` sau un card din `Deals/Kanban` — aceeași formă pentru
 * amândouă (plan §1.2 regula 4 nu impune un Resource per pagină, ci un contract per
 * formă de date; lista și kanban-ul arată exact aceleași câmpuri pe fiecare deal).
 *
 * `can` e per RÂND, nu doar per pagină: US-CRM-02 cere ca „coloanele de editare" să
 * rămână active doar pe înregistrările proprii ale unui Agent, ceea ce un `can` unic de
 * pagină nu poate exprima. FR-RBAC-01 rămâne respectat — front-end-ul ascunde acțiunea,
 * nu doar o dezactivează.
 *
 * @mixin Deal
 */
class DealSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'value' => $this->value !== null ? (float) $this->value : null,
            'currency' => $this->currency,
            'expectedCloseDate' => $this->expected_close_date?->toDateString(),
            'status' => $this->status,
            'lostReason' => $this->lost_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            // FR-TEN-04 — placeholder „(deactivated)" pe owner (§6.4.1).
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => DeactivatedMemberNames::label($this->owner->name, $this->owner->id),
            ]),
            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'isWon' => $this->stage->is_won,
                'isLost' => $this->stage->is_lost,
            ]),
            'can' => [
                'edit' => Gate::allows('update', $this->resource),
                'moveStage' => Gate::allows('moveStage', $this->resource),
            ],
        ];
    }
}
