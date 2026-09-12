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
     * @return array{slug: string, name: string, industry?: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            ...($this->withIndustry ? ['industry' => $this->industry] : []),
        ];
    }
}
