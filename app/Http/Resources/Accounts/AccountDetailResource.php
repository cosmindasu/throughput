<?php

namespace App\Http\Resources\Accounts;

use App\Models\Account;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Accounts/Show` și `Accounts/Edit` — forma completă a unui cont (§8.1). Adresele sunt
 * deja `jsonb` pe model (cast `array`); aici doar se normalizează cheile la camelCase
 * (`postal_code` → `postalCode`), ca React să nu vadă niciodată snake_case (plan §1.2).
 *
 * @mixin Account
 */
class AccountDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'industry' => $this->industry,
            'phone' => $this->phone,
            'status' => $this->status,
            'creditTerms' => $this->credit_terms,
            'source' => $this->source,
            'tags' => $this->tags ?? [],
            'billingAddress' => $this->address($this->billing_address),
            'shippingAddress' => $this->address($this->shipping_address),
            // FR-TEN-04 — vezi `AccountResource` pentru motiv.
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => DeactivatedMemberNames::label($this->owner->name, $this->owner->id)] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string|null>|null  $address
     * @return array{line1: string|null, city: string|null, state: string|null, postalCode: string|null, country: string|null}|null
     */
    private function address(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'line1' => $address['line1'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
        ];
    }
}
