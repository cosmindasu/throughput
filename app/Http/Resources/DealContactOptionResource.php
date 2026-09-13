<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O intrare a listei „Primary contact" din `Deals/Create` și `Deals/Edit` — doar
 * contactele contului deal-ului (§9 task: „contact principal dintre contactele
 * contului"). Numit `Deal…`, nu `ContactOptionResource`: contactul complet (CRUD) e alt
 * pachet, care își poate defini propriul Resource fără coliziune de nume.
 *
 * @mixin Contact
 */
class DealContactOptionResource extends JsonResource
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
