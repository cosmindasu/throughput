<?php

namespace App\Http\Resources\Members;

use App\Models\Membership;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `Settings/Members` — un rând per membership al tenantului curent (§6.4). Rândul de
 * membership RĂMÂNE la dezactivare (BR-TEN-04): `status`/`deactivatedAt`/`deactivatedBy`
 * fac diferența, nu un `DELETE` care ar rupe istoricul.
 *
 * `canDeactivate`/`isLastActiveOwner` vin din CONTROLLER, nu se recalculează aici din rol —
 * motivul e că `isLastActiveOwner` cere numărul de Owner-i activi ai tenantului, calculat O
 * SINGURĂ DATĂ pentru toată pagina (nu per rând), altfel fiecare rând Owner ar repeta
 * aceeași interogare (`App\Support\Members\ActiveOwners`).
 *
 * @mixin Membership
 */
final class MembershipResource extends JsonResource
{
    /**
     * @param  array{deals: int, orders: int, total: int}  $openRecords
     */
    public function __construct(
        Membership $resource,
        private readonly bool $canDeactivate = false,
        private readonly bool $isLastActiveOwner = false,
        private readonly array $openRecords = ['deals' => 0, 'orders' => 0, 'total' => 0],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            // Team-scoped (spatie/laravel-permission, `PermissionRegistrar::setPermissionsTeamId()`
            // deja apelat de `ResolveWorkspace`) — un singur rol per tenant, §7.2.
            'role' => $this->user->getRoleNames()->first(),
            'status' => $this->status,
            'joinedAt' => $this->created_at?->toIso8601String(),
            'deactivatedAt' => $this->deactivated_at?->toIso8601String(),
            // P3 (review general) — cine a dezactivat rândul poate el însuși fi
            // dezactivat între timp (§6.4.1); placeholder-ul FR-TEN-04 se aplică la fel
            // ca pe orice altă referință de actor.
            'deactivatedBy' => $this->deactivatedBy ? [
                'id' => $this->deactivatedBy->id,
                'name' => DeactivatedMemberNames::label($this->deactivatedBy->name, $this->deactivatedBy->id),
            ] : null,
            // BR-TEN-06 — „12 open deals and 3 active orders", numărul exact din
            // confirmarea de dezactivare, precalculat aici ca dialogul din interfață să nu
            // ceară o cerere separată doar ca să-l afle.
            'openRecords' => $this->openRecords,
            'canDeactivate' => $this->canDeactivate,
            // BR-TEN-01 — „blocare server-side, NU doar ascunsă în UI": butonul rămâne
            // vizibil (dacă `canDeactivate`), dar dialogul arată mesajul de blocare, fără
            // butoane de acțiune, când acest membru e singurul Owner activ.
            'isLastActiveOwner' => $this->isLastActiveOwner,
        ];
    }
}
