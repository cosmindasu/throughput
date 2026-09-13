<?php

namespace App\Http\Resources;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detaliul complet al unui deal (`Deals/Show`, `Deals/Edit`). `can` NU e aici — e propul
 * de pagină calculat de controller (plan §1.2 regula 2), ca să rămână un singur loc care
 * decide ce poate face utilizatorul CU PAGINA, distinct de forma datelor.
 *
 * @mixin Deal
 */
class DealResource extends JsonResource
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
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            // `isAnonymized` (§20.5) — controllerul (`show()`/`edit()`) încarcă acest contact
            // cu bypass explicit al `NotAnonymizedContactScope`: un contact principal
            // anonimizat rămâne vizibil AICI (istoric), chiar dacă a dispărut din restul
            // aplicației. Interfața decide singură ce face cu steagul — text neutru fără
            // link pe `Deals/Show`, opțiune informativă pe `Deals/Edit`.
            'primaryContact' => $this->whenLoaded(
                'primaryContact',
                fn () => $this->primaryContact ? [
                    'id' => $this->primaryContact->id,
                    'name' => trim($this->primaryContact->first_name.' '.$this->primaryContact->last_name),
                    'isAnonymized' => $this->primaryContact->isAnonymized(),
                ] : null
            ),
            'pipeline' => $this->whenLoaded('pipeline', fn () => [
                'id' => $this->pipeline->id,
                'name' => $this->pipeline->name,
            ]),
            'stage' => $this->whenLoaded('stage', fn () => [
                'id' => $this->stage->id,
                'name' => $this->stage->name,
                'isWon' => $this->stage->is_won,
                'isLost' => $this->stage->is_lost,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
