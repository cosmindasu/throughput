<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Workspace curent (`workspace`, cu `industry` — FR-DEMO-01, titlul dashboard-ului) și
 * intrările din comutator (`workspaces`, doar `slug`/`name` — plan §1.2 regula 3).
 *
 * Aceeași clasă acoperă ambele forme din contractul de props (§7.1 din plan, comutatorul
 * de workspace): `WorkspaceResource::make()` pentru forma completă, `::summary()` pentru
 * lista comutatorului, ca să nu existe două clase pentru același model.
 *
 * @mixin Tenant
 */
class WorkspaceResource extends JsonResource
{
    private bool $withIndustry = true;

    public static function summary(mixed $resource): self
    {
        $instance = new self($resource);
        $instance->withIndustry = false;

        return $instance;
    }

    /**
     * `currency` intră în forma completă, nu în cea de comutator: specs.md §2.3 — „o
     * singură monedă per tenant (implicit USD), configurabilă". Orice ecran care afișează
     * bani are nevoie de ea ca prop comun, nu ca o constantă scrisă în componentă —
     * dashboard-ul o fixase la `'USD'`, iar pe un tenant pe altă monedă afișa simbolul
     * greșit (găsit la un audit încrucișat, Faza 5).
     *
     * @return array{slug: string, name: string, industry?: string|null, currency?: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            ...($this->withIndustry ? ['industry' => $this->industry, 'currency' => $this->currency] : []),
        ];
    }
}
