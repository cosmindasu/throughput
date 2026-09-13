<?php

namespace App\Http\Resources;

use App\Models\DealStageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare din istoricul de tranziții al unui deal (`Deals/Show`, §9.1). Append-only —
 * acest Resource e doar de CITIRE, nu există `Requests\Deals\*StageEvent*`.
 *
 * @mixin DealStageEvent
 */
class DealStageEventResource extends JsonResource
{
    /**
     * @return array{id: string, fromStage: array{id: string, name: string}|null, toStage: array{id: string, name: string}, changedBy: array{id: string, name: string}|null, changedAt: string|null, durationInPreviousStageSeconds: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fromStage' => $this->whenLoaded('fromStage', fn () => $this->fromStage ? [
                'id' => $this->fromStage->id,
                'name' => $this->fromStage->name,
            ] : null),
            'toStage' => $this->whenLoaded('toStage', fn () => [
                'id' => $this->toStage->id,
                'name' => $this->toStage->name,
            ]),
            'changedBy' => $this->whenLoaded('changedBy', fn () => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ] : null),
            'changedAt' => $this->changed_at?->toIso8601String(),
            'durationInPreviousStageSeconds' => $this->duration_in_previous_stage_seconds,
        ];
    }
}
