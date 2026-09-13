<?php

namespace App\Http\Resources\Accounts;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lista de contacte de pe `Accounts/Show` (FR-CRM-04). Doar pentru afișare — CRUD-ul
 * contactelor e alt pachet (`App\Http\Resources\ContactResource`, dacă și când apare, nu
 * există încă în acest branch); de aceea versiunea de aici e minimală și trăiește sub
 * namespace-ul `Accounts`, ca să nu se bată cu ea la integrare.
 *
 * @mixin Contact
 */
class AccountContactResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, email: string|null, phone: string|null, title: string|null, isPrimary: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'isPrimary' => (bool) $this->is_primary,
        ];
    }
}
