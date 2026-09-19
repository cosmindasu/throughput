<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/**
 * §6.4 + §6.4.1, [[ADR-011]].
 *
 * Regula de fond, care e o DECIZIE, nu o scăpare: dezactivarea unui membru **nu e
 * niciodată blocată** de înregistrările pe care le deține (BR-TEN-03). Varianta anterioară
 * a specificației cerea reatribuirea prealabilă a tuturor înregistrărilor (tiparul Zoho);
 * a fost respinsă pentru că blochează exact lucrul care trebuie să se întâmple imediat —
 * revocarea accesului cuiva care pleacă în conflict. Confirmarea din interfață (BR-TEN-06)
 * INFORMEAZĂ; nu blochează.
 *
 * Singura blocare absolută e ultimul Owner activ (BR-TEN-01): un workspace fără proprietar
 * nu are un „mai târziu".
 */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('members.view');
    }

    public function invite(User $user): bool
    {
        return $user->can('members.invite');
    }

    /**
     * BR-TEN-02: doar un Owner poate schimba rolul unui alt Owner sau poate promova pe
     * cineva la Owner. Un Manager gestionează Agent/Viewer și atât.
     */
    public function updateRole(User $user, Membership $membership, ?string $newRole = null): Response
    {
        if (! $user->can('members.update_role')) {
            return Response::deny('You cannot change roles in this workspace.');
        }

        $touchesOwnership = $this->isOwner($membership) || $newRole === Permissions::OWNER;

        if ($touchesOwnership && ! $user->hasRole(Permissions::OWNER)) {
            return Response::deny('Only an Owner can promote or demote another Owner.');
        }

        if ($this->isOwner($membership) && $newRole !== Permissions::OWNER && $this->isLastActiveOwner($membership)) {
            return Response::deny('A workspace needs at least one Owner.');
        }

        return Response::allow();
    }

    public function deactivate(User $user, Membership $membership): Response
    {
        if (! $user->can('members.deactivate')) {
            return Response::deny('You cannot deactivate members in this workspace.');
        }

        if ($this->isOwner($membership) && ! $user->hasRole(Permissions::OWNER)) {
            return Response::deny('Only an Owner can deactivate another Owner.');
        }

        // P2-001 (review general, lotul „Membri și roluri") — idempotență. Un al doilea
        // POST pe un membership deja dezactivat (dublu-click, retry, două cereri
        // concurente) trecea de Policy, rescria `deactivated_at`/`deactivated_by` cu date
        // noi, scria în `activity_log` un `old_values.status = active` FALS (rândul era
        // deja `deactivated`), retrimitea notificarea „N records need a new owner" și
        // putea porni un al doilea grup de reatribuire pentru aceleași înregistrări.
        // ÎNAINTE de verificarea ultimului Owner — un membership deja dezactivat nu mai e
        // Owner activ, deci ar trece silențios pe lângă acea verificare oricum.
        if (! $membership->isActive()) {
            return Response::deny('This member is already deactivated.');
        }

        if ($this->isLastActiveOwner($membership)) {
            // Singurul caz în care blocarea e corectă — și singurul fără buton de forțare.
            return Response::deny('Transfer ownership before deactivating the last Owner.');
        }

        // P2-004 (review general) — decizie: auto-dezactivarea e blocată. DUPĂ verificarea
        // ultimului Owner, deliberat: dacă cineva e ȘI ultimul Owner activ ȘI se
        // dezactivează pe sine, mesajul „Transfer ownership…" rămâne cel corect (mai
        // specific — arată calea de urmat), nu „nu te poți dezactiva singur" (adevărat,
        // dar mai puțin util aici). Pentru restul cazurilor (nu ești ultimul Owner, dar
        // încerci să-ți revoci singur accesul): acțiunea n-are niciun „mai târziu" de
        // gestionat de pe acest ecran — cere altui Owner/Manager s-o facă.
        if ($membership->user_id === $user->getKey()) {
            return Response::deny("You can't deactivate yourself. Ask another Owner or Manager.");
        }

        // Deliberat NU verificăm ce deține membrul. 47 de conturi, 12 oportunități deschise
        // și 3 comenzi active nu sunt un motiv de refuz (BR-TEN-03); ele ajung în vederea
        // „Unassigned" (BR-TEN-05, Faza 2).
        return Response::allow();
    }

    private function isOwner(Membership $membership): bool
    {
        return $membership->user?->hasRole(Permissions::OWNER) ?? false;
    }

    private function isLastActiveOwner(Membership $membership): bool
    {
        if (! $this->isOwner($membership) || ! $membership->isActive()) {
            return false;
        }

        return $this->activeOwnerCount($membership) <= 1;
    }

    private function activeOwnerCount(Membership $membership): int
    {
        $ownerIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Permissions::OWNER)
            ->where('roles.tenant_id', $membership->tenant_id)
            ->where('model_has_roles.tenant_id', $membership->tenant_id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('model_has_roles.model_id');

        // `Membership` e scopat de global scope pe tenantul curent; sub RLS, chiar și un
        // ocol prin query builder ar vedea tot doar tenantul curent.
        return Membership::query()
            ->whereIn('user_id', $ownerIds)
            ->where('status', Membership::STATUS_ACTIVE)
            ->count();
    }
}
