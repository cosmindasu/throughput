<?php

namespace App\Http\Resources\Orders;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare a listei „Contact" din `Orders/Create` și `Orders/Edit` — doar contactele
 * contului comenzii, la fel ca `DealContactOptionResource`.
 *
 * @mixin Contact
 */
final class OrderContactOptionResource extends JsonResource
{
    /**
     * @return array{id: string, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim($this->first_name.' '.$this->last_name),
        ];
    }
}
