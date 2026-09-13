<?php

namespace App\Http\Resources;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare a listei „Owner" din `Deals/Create` și `Deals/Edit` — randată DOAR când
 * `can.changeOwner` e adevărat (§7.4: `deals.change_owner` există doar la Owner/Manager).
 * Membrii activi ai tenantului curent, nu toți utilizatorii din `users` (tabelă globală,
 * fără `tenant_id` — vezi `DealResource`-urile de validare din `app/Http/Requests/Deals`).
 *
 * @mixin Membership
 */
class DealOwnerOptionResource extends JsonResource
{
    /**
     * @return array{id: string, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
        ];
    }
}
