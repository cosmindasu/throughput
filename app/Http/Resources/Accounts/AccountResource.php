<?php

namespace App\Http\Resources\Accounts;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rândul din `Accounts/Index` (FR-CRM-03). `canEdit` e PER RÂND, nu per pagină — un Agent
 * vede tot tenantul pe „All accounts", dar editează doar ce deține/a creat (§7.5); un
 * `can.edit` de pagină ar fi fost fals pentru tot restul rândurilor.
 *
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, domain: string|null, industry: string|null, status: string, owner: array{id: string, name: string}|null, createdAt: string|null, canEdit: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'industry' => $this->industry,
            'status' => $this->status,
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'canEdit' => (bool) $request->user()?->can('update', $this->resource),
        ];
    }
}
