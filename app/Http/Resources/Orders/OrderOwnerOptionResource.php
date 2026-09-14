<?php

namespace App\Http\Resources\Orders;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare a listei „Owner" din `Orders/Create`/`Orders/Edit` — membrii activi ai
 * tenantului curent, la fel ca `DealOwnerOptionResource`. Randată doar pentru
 * utilizatori care nu sunt restrânși la înregistrări proprii (Owner/Manager, §7.5).
 *
 * @mixin Membership
 */
final class OrderOwnerOptionResource extends JsonResource
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
