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
 *
 * ADR-022 / specs.md §15.8 FR-I18N-04 — TEXTUL fiecărui refuz stă în `lang/{en,fr}/rules.php`
 * (`rules.members.*`), nu ca literal aici: mesajele astea se randează în dialogul de pe
 * `Settings/Members`, deci sunt interfață, nu jurnal. Numele rolurilor vin din
 * `lang/{locale}/roles.php` prin `:owner`/`:manager`, sursa unică decisă de proprietar —
 * niciodată scrise în fraza tradusă, fiindcă ar diverge tăcut la o redenumire de rol.
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
     * BR-TEN-02, a doua jumătate — „doar Owner și Manager pot invita membri; doar Owner
     * poate schimba rolul unui alt Owner". Nota ¹ de la matricea §7.4 o spune explicit
     * pentru invitare: Managerul invită Agent/Viewer, „dar nu poate promova pe cineva la
     * Owner". O invitație CU rolul Owner e o promovare făcută înainte ca persoana să
     * existe ca membru — aceeași regulă, altă poartă.
     *
     * Simetrică deliberat cu `updateRole()`: un Manager care nu poate PROMOVA la Owner, dar
     * ar putea INVITA direct un Owner, ar avea o cale ocolită către exact acelaşi rezultat.
     *
     * `Response`, nu `bool`: refuzul se întoarce în dialog cu textul lui (audit de
     * accesibilitate P1 — vezi `MembersController::deactivate()`), nu ca 403 opac.
     */
    public function inviteWithRole(User $user, string $role): Response
    {
        if (! $user->can('members.invite')) {
            return Response::deny(__('rules.members.cannot_invite'));
        }

        if ($role === Permissions::OWNER && ! $user->hasRole(Permissions::OWNER)) {
            return Response::deny(__('rules.members.owner_invites_owner', ['owner' => __('roles.owner')]));
        }

        return Response::allow();
    }

    /**
     * Retrimiterea unui link de acceptare e aceeași acțiune ca invitarea (aceeași
     * permisiune, același invitat, alt token) — deci aceeași regulă de rol. Blocată pe un
     * rând care nu mai e „pending": un membru activ n-are ce accepta, iar un link nou pe un
     * rând dezactivat ar fi o reinstaurare tăcută, nu o retrimitere.
     */
    public function resendInvitation(User $user, Membership $membership): Response
    {
        if ($membership->status !== Membership::STATUS_PENDING) {
            return Response::deny(__('rules.members.invitation_not_pending'));
        }

        return $this->inviteWithRole($user, (string) $membership->user?->getRoleNames()->first());
    }

    /**
     * Retragerea unei invitații neacceptate. Nu e „eliminarea unui membru" (BR-TEN-01/04 nu
     * se aplică: un invitat nu e Owner activ și n-are istoric de păstrat), dar rămâne sub
     * aceeași regulă de rol ca invitarea lui — cine n-ar fi putut trimite invitația nu
     * trebuie nici s-o poată retrage.
     */
    public function revokeInvitation(User $user, Membership $membership): Response
    {
        if ($membership->status !== Membership::STATUS_PENDING) {
            return Response::deny(__('rules.members.invitation_not_pending'));
        }

        return $this->inviteWithRole($user, (string) $membership->user?->getRoleNames()->first());
    }

    /**
     * BR-TEN-02: doar un Owner poate schimba rolul unui alt Owner sau poate promova pe
     * cineva la Owner. Un Manager gestionează Agent/Viewer și atât.
     */
    public function updateRole(User $user, Membership $membership, ?string $newRole = null): Response
    {
        if (! $user->can('members.update_role')) {
            return Response::deny(__('rules.members.cannot_change_roles'));
        }

        $touchesOwnership = $this->isOwner($membership) || $newRole === Permissions::OWNER;

        if ($touchesOwnership && ! $user->hasRole(Permissions::OWNER)) {
            return Response::deny(__('rules.members.owner_changes_owner', ['owner' => __('roles.owner')]));
        }

        if ($this->isOwner($membership) && $newRole !== Permissions::OWNER && $this->isLastActiveOwner($membership)) {
            return Response::deny(__('rules.members.last_owner_required', ['owner' => __('roles.owner')]));
        }

        return Response::allow();
    }

    public function deactivate(User $user, Membership $membership): Response
    {
        if (! $user->can('members.deactivate')) {
            return Response::deny(__('rules.members.cannot_deactivate'));
        }

        if ($this->isOwner($membership) && ! $user->hasRole(Permissions::OWNER)) {
            return Response::deny(__('rules.members.owner_deactivates_owner', ['owner' => __('roles.owner')]));
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
            return Response::deny(__('rules.members.already_deactivated'));
        }

        if ($this->isLastActiveOwner($membership)) {
            // Singurul caz în care blocarea e corectă — și singurul fără buton de forțare.
            return Response::deny(__('rules.members.transfer_ownership_first', ['owner' => __('roles.owner')]));
        }

        // P2-004 (review general) — decizie: auto-dezactivarea e blocată. DUPĂ verificarea
        // ultimului Owner, deliberat: dacă cineva e ȘI ultimul Owner activ ȘI se
        // dezactivează pe sine, mesajul „Transfer ownership…" rămâne cel corect (mai
        // specific — arată calea de urmat), nu „nu te poți dezactiva singur" (adevărat,
        // dar mai puțin util aici). Pentru restul cazurilor (nu ești ultimul Owner, dar
        // încerci să-ți revoci singur accesul): acțiunea n-are niciun „mai târziu" de
        // gestionat de pe acest ecran — cere altui Owner/Manager s-o facă.
        if ($membership->user_id === $user->getKey()) {
            return Response::deny(__('rules.members.cannot_deactivate_self', [
                'owner' => __('roles.owner'),
                'manager' => __('roles.manager'),
            ]));
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
