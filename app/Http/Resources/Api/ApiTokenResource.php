<?php

namespace App\Http\Resources\Api;

use App\Models\ApiToken;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rândul din Settings → API tokens (specs.md §18.1, FR-API-02).
 *
 * Aici NU există și nu poate exista jetonul în clar: `api_tokens` păstrează doar
 * `token_hash`, iar valoarea în clar e întoarsă o singură dată, de `ApiToken::issue()`,
 * la creare (US-API-01: „nu mai e recuperabilă din UI ulterior"). Ecranul primește doar
 * un prefix de identificare vizuală, la fel de puțin ca preview-ul de cheie de curierat.
 *
 * @mixin ApiToken
 */
final class ApiTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities ?? [],
            // FR-TEN-04 — placeholder „(deactivated)" pe emitent, ca peste tot altundeva.
            'createdBy' => $this->whenLoaded('user', fn () => $this->user
                ? DeactivatedMemberNames::label($this->user->name, $this->user->id)
                : null),
            'createdAt' => $this->created_at?->toIso8601String(),
            'lastUsedAt' => $this->last_used_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'revokedAt' => $this->revoked_at?->toIso8601String(),
            'status' => $this->statusLabel(),
        ];
    }

    private function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        return $this->isExpired() ? 'expired' : 'active';
    }
}
