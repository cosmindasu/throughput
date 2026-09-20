<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul PUBLIC al unui cont (specs.md §18). Separat deliberat de
 * `App\Http\Resources\Accounts\*`, care e contractul de props Inertia: ecranele se
 * schimbă la fiecare iterație de interfață, un API versionat pe cale (ADR-008) nu are
 * voie. Două consumatoare, două contracte.
 *
 * @mixin Account
 */
final class AccountResource extends JsonResource
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
            'status' => $this->status,
            'phone' => $this->phone,
            'creditTerms' => $this->credit_terms,
            'source' => $this->source,
            'tags' => $this->tags ?? [],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
