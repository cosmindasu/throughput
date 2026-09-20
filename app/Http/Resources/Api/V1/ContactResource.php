<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul public al unui contact (specs.md §18).
 *
 * Contactele ANONIMIZATE (§20.5) nu ajung niciodată aici: `NotAnonymizedContactScope` le
 * scoate din orice interogare normală, inclusiv din cele ale acestui API. Nu există, în
 * API, echivalentul ocolirii explicite pe care o fac ecranele de detaliu — un consumator
 * extern n-are ce face cu un rând golit.
 *
 * @mixin Contact
 */
final class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accountId' => $this->account_id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'isPrimary' => (bool) $this->is_primary,
            'optOut' => (bool) $this->opt_out,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
