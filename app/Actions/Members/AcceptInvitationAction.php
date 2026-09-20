<?php

namespace App\Actions\Members;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Members\DeactivatedMemberIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * US-TEN-01 — capătul celălalt al invitației: `status pending` → `active`, tokenul consumat.
 *
 * Rulează pe o rută PUBLICĂ, deci fără niciun context ambiental: tot ce atinge `memberships`
 * stă într-un `TenantContext::run()` explicit, cu tenantul rezolvat deja din slug
 * (`PendingInvitation`). `users` e tabelă globală, fără RLS (§19.1), deci profilul se scrie
 * în afara contextului fără nicio diferență.
 */
final class AcceptInvitationAction
{
    /**
     * Reverificarea SUB blocare (acelaşi tipar ca `MembersController::lockAndApplyDeactivation()`,
     * P2-001): între ecranul de acceptare și `POST` pot trece minute, iar invitația poate fi
     * revocată, retrimisă (token nou) sau expirată între timp. Fără reverificare, un token
     * deja invalidat ar activa totuși membership-ul.
     *
     * @param  ?string  $name  numele ales de invitat; `null` dacă avea deja cont
     * @param  ?string  $password  parola aleasă de invitat; `null` dacă avea deja cont
     *
     * @throws RuntimeException dacă invitația nu mai e validă sub blocare
     */
    public function execute(PendingInvitation $invitation, ?string $name, ?string $password, Request $request): User
    {
        $tokenHash = (string) $invitation->membership->invitation_token;
        $user = $invitation->membership->user;

        if ($user === null) {
            throw new RuntimeException('Invitation without a user row.');
        }

        TenantContext::run($invitation->tenant, function () use ($invitation, $tokenHash, $user, $request): void {
            $locked = Membership::query()
                ->whereKey($invitation->membership->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->status !== Membership::STATUS_PENDING
                || $locked->invitation_token !== $tokenHash
                || $locked->invitation_expires_at === null
                || $locked->invitation_expires_at->isPast()) {
                throw new RuntimeException('Invitation is no longer valid.');
            }

            $locked->update([
                'status' => Membership::STATUS_ACTIVE,
                // Consumat: un al doilea click pe același link nu mai găsește nimic.
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ]);

            // FR-TEN-04 — acceptarea poate reactiva un membru dezactivat mai devreme
            // (`InviteMemberAction::upsertPendingMembership()` re-invită un rând
            // `deactivated`): setul memoizat de id-uri dezactivate trebuie golit ÎN aceeași
            // cerere, altfel numele ar rămâne „(deactivated)" până la cererea următoare.
            app(DeactivatedMemberIds::class)->forgetCurrentTenant();

            ActivityLog::query()->create([
                'user_id' => $user->getKey(),
                'action' => 'updated',
                'auditable_type' => Membership::class,
                'auditable_id' => $locked->getKey(),
                'old_values' => ['status' => Membership::STATUS_PENDING],
                'new_values' => ['status' => Membership::STATUS_ACTIVE],
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });

        $this->completeProfile($user, $name, $password);

        return $user->refresh();
    }

    /**
     * `email_verified_at` e marcajul „acest cont a fost revendicat de cineva care citește
     * emailul lui" — pus AICI, nu la invitare: până la acceptare rândul `users` e doar o
     * identitate rezervată, fără nicio dovadă că adresa aparține cuiva.
     * `PendingInvitation::needsProfile()` citește exact acest marcaj.
     */
    private function completeProfile(User $user, ?string $name, ?string $password): void
    {
        $attributes = ['email_verified_at' => $user->email_verified_at ?? now()];

        if ($name !== null && $name !== '') {
            $attributes['name'] = $name;
        }

        if ($password !== null && $password !== '') {
            $attributes['password'] = Hash::make($password);
        }

        $user->forceFill($attributes)->save();
    }
}
