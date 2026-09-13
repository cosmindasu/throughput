<?php

namespace App\Http\Resources\Accounts;

use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lista de deals de pe `Accounts/Show` (FR-CRM-04). Ca și `AccountContactResource`, e
 * doar pentru afișare — pachetul de Pipeline/Deals își are propriile Resources pentru
 * kanban și pagina de detaliu.
 *
 * @mixin Deal
 */
class AccountDealResource extends JsonResource
{
    /**
     * @return array{id: string, title: string, stageName: string|null, value: float|null, currency: string, status: string, url: string}
     */
    public function toArray(Request $request): array
    {
        $workspace = app()->bound('tenant') ? app('tenant')->slug : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'stageName' => $this->stage?->name,
            'value' => $this->value !== null ? (float) $this->value : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'url' => $workspace !== null ? "/{$workspace}/deals/{$this->id}" : '#',
        ];
    }
}
