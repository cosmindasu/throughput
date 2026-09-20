<?php

namespace App\Actions\Members;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * US-TEN-02 — retragerea unei invitații care n-a fost încă acceptată.
 *
 * DELETE fizic, spre deosebire de dezactivarea unui membru (BR-TEN-04, care interzice
 * explicit `DELETE` pe `memberships`). Nu e o inconsecvență: BR-TEN-04 protejează
 * ISTORICUL unui membru real — „rândul rămâne, cu rolul și data aderării, pentru istoric
 * auditabil". O invitație neacceptată nu are istoric de protejat: nimeni n-a intrat
 * niciodată în workspace pe ea, nu deține nicio înregistrare, iar rândul e chiar lucrul
 * care trebuie să dispară, ca `unique(tenant_id, user_id)` să permită o invitație nouă
 * către aceeași persoană. Faptul că invitația a existat și a fost retrasă rămâne în
 * `activity_log` (§17), unde îi e locul.
 *
 * Rândul `users` creat de invitație (vezi `InviteMemberAction`) NU se șterge — limitare
 * cunoscută, semnalată în raportul lotului: a decide „utilizatorul ăsta n-are niciun alt
 * membership" ar cere o interogare cross-tenant peste `memberships`, pe care politica RLS
 * a tabelei o face imposibilă pentru un ALT utilizator decât cel autentificat
 * (`app.user_id`, ADR-014 pct. 2). Rândul rămas e o identitate fără parolă utilizabilă,
 * fără niciun membership activ, refolosită dacă aceeași adresă e invitată din nou.
 */
final class RevokeInvitationAction
{
    public function execute(User $actor, Membership $membership, Request $request): void
    {
        DB::transaction(function () use ($actor, $membership, $request): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->with('user')->first();

            if ($locked === null || $locked->status !== Membership::STATUS_PENDING) {
                return;
            }

            $user = $locked->user;
            $role = null;

            if ($user !== null) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($locked->tenant_id);
                $user->unsetRelation('roles');
                $role = $user->getRoleNames()->first();
                // Rolul e per tenant (`model_has_roles.tenant_id`) — fără linia asta, un
                // invitat revocat ar rămâne cu rol în workspace-ul din care tocmai i-am
                // retras invitația. Fără membership activ n-ar avea acces oricum, dar un
                // rând de rol orfan ar face `MembershipPolicy::activeOwnerCount()` să
                // numere pe cineva care nu mai există ca membru.
                $user->syncRoles([]);
            }

            // Scris ÎNAINTE de `delete()`: `activity_log.auditable_id` nu are FK (jurnal
            // append-only, §17.1), dar rândul citit trebuie să existe cât îi compunem
            // valorile vechi.
            ActivityLog::query()->create([
                'user_id' => $actor->getKey(),
                'action' => 'deleted',
                'auditable_type' => Membership::class,
                'auditable_id' => $locked->getKey(),
                'old_values' => [
                    'status' => Membership::STATUS_PENDING,
                    'email' => $user?->email,
                    'role' => $role,
                ],
                'new_values' => null,
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            $locked->delete();
        });
    }
}
