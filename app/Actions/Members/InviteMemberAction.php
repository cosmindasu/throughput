<?php

namespace App\Actions\Members;

use App\Mail\MemberInvitationMail;
use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * US-TEN-01, §6.4 — „se creează un membership cu status «pending» și un token de acceptare".
 *
 * FORMA ALEASĂ PENTRU INVITAȚII (decizie, argumentată în raportul lotului): invitația E un
 * rând `memberships` cu `status = 'pending'`, nu o tabelă nouă. Motivele, în ordinea
 * greutății:
 *
 *  1. Schema o prevede deja din Faza 1 — `memberships.invitation_token` și
 *     `invitation_expires_at` există în migrația `2026_09_12_100010`, exact ca în
 *     `plan-implementare.md` §7.6, grupul B; `Membership::STATUS_PENDING` și cast-ul pe
 *     `invitation_expires_at` la fel. O tabelă nouă ar fi însemnat o migrație TÂRZIU într-o
 *     fază, peste coloane deja migrate și nefolosite.
 *  2. Gherkin-ul US-TEN-01 e literal: „se creează un MEMBERSHIP cu status «pending»".
 *  3. `unique(tenant_id, user_id)` devine garanția, la nivel de bază, că nu poate exista
 *     simultan o invitație și un membership pentru aceeași persoană în același workspace —
 *     invariantă pe care o tabelă separată ar fi cerut-o replicată în cod.
 *  4. Politica RLS a lui `memberships` (`app.user_id` SAU `app.tenant_id`, ADR-014 pct. 2)
 *     e deja scrisă de mână și deja justificată de un ADR. O tabelă nouă ar fi avut nevoie
 *     de politică proprie, iar [[ADR-020]] cere explicit un ADR pentru al treilea apelant
 *     al lui `enableRlsWithPolicy()` — nu un commit.
 *
 * COSTUL, asumat și raportat: `memberships.user_id` e `NOT NULL` cu FK spre `users`, deci
 * invitația are nevoie de un rând `users` ÎNAINTE de acceptare. E creat aici, cu parolă
 * aleatoare nefolosibilă și `email_verified_at = null` — identitatea globală (§19.1), nu un
 * cont funcțional: fără membership activ, autentificarea nu deschide niciun workspace.
 * Emailul e cheia naturală, deci o a doua invitație către aceeași adresă REFOLOSEȘTE rândul,
 * nu îl multiplică.
 */
final class InviteMemberAction
{
    /**
     * @param  string  $role  unul din `Permissions::roles()`; drepturile de a-l acorda sunt
     *                        deja verificate de `MembershipPolicy::invite()`/`updateRole()`
     * @return array{membership: Membership, plainToken: string, isNewUser: bool}
     */
    public function execute(User $actor, string $email, string $role, Request $request): array
    {
        $email = Str::lower(trim($email));
        $plainToken = InvitationToken::generate();

        /** @var array{membership: Membership, plainToken: string, isNewUser: bool} $result */
        $result = DB::transaction(function () use ($actor, $email, $role, $plainToken, $request): array {
            $existing = User::query()->where('email', $email)->first();
            $isNewUser = $existing === null;

            $user = $existing ?? User::query()->create([
                'name' => InvitationToken::placeholderNameFor($email),
                'email' => $email,
                // Nefolosibilă: `Hash::make()` peste 64 de octeți aleatori pe care nimeni
                // nu îi vede vreodată. Acceptarea invitației o înlocuiește cu parola aleasă
                // de invitat; până atunci, „recuperare parolă" rămâne singura cale spre
                // acest cont — și nici ea nu dă acces la workspace fără membership activ.
                'password' => Hash::make(Str::random(64)),
            ]);

            $membership = $this->upsertPendingMembership($user, $plainToken);

            app(PermissionRegistrar::class)->setPermissionsTeamId(app('tenant')->getKey());
            $user->syncRoles([$role]);

            ActivityLog::query()->create([
                'user_id' => $actor->getKey(),
                'action' => 'created',
                'auditable_type' => Membership::class,
                'auditable_id' => $membership->getKey(),
                'old_values' => null,
                // Adresa invitatului e chiar subiectul acțiunii — fără ea rândul de jurnal
                // ar spune „a fost creat un membership", fără să spună al cui.
                'new_values' => ['status' => Membership::STATUS_PENDING, 'email' => $email, 'role' => $role],
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            return ['membership' => $membership, 'plainToken' => $plainToken, 'isNewUser' => $isNewUser];
        });

        $this->send($result['membership'], $email, $role, $plainToken, $actor);

        return $result;
    }

    /**
     * Retrimitere (US-TEN-01, „link de acceptare valid 7 zile"): token NOU și fereastră
     * NOUĂ, nu o copie a celui vechi. Un link retrimis îl invalidează pe cel dinainte —
     * altfel două linkuri valide ar circula pentru aceeași invitație, iar revocarea unuia
     * n-ar însemna nimic.
     */
    public function resend(User $actor, Membership $membership): string
    {
        $plainToken = InvitationToken::generate();

        DB::transaction(function () use ($membership, $plainToken): void {
            $membership->update([
                'invitation_token' => InvitationToken::hash($plainToken),
                'invitation_expires_at' => now()->addDays(InvitationToken::VALID_FOR_DAYS),
            ]);
        });

        $membership->loadMissing('user');

        $this->send(
            $membership,
            (string) $membership->user?->email,
            (string) $membership->user?->getRoleNames()->first(),
            $plainToken,
            $actor,
        );

        return $plainToken;
    }

    /**
     * Un rând `deactivated` poate fi re-invitat: `unique(tenant_id, user_id)` face
     * imposibil un al doilea rând, iar BR-TEN-04 („dezactivarea nu e un DELETE") nu cere ca
     * rândul să rămână dezactivat pe veci — istoricul acțiunii trăiește în `activity_log`.
     * `deactivated_at`/`deactivated_by` se golesc aici, ca lista de membri să nu arate
     * simultan „Invited" și o dată de dezactivare.
     */
    private function upsertPendingMembership(User $user, string $plainToken): Membership
    {
        $membership = Membership::query()->where('user_id', $user->getKey())->first();

        $attributes = [
            'status' => Membership::STATUS_PENDING,
            'invitation_token' => InvitationToken::hash($plainToken),
            'invitation_expires_at' => now()->addDays(InvitationToken::VALID_FOR_DAYS),
            'deactivated_at' => null,
            'deactivated_by' => null,
        ];

        if ($membership !== null) {
            $membership->update($attributes);

            return $membership->refresh();
        }

        return Membership::query()->create($attributes + ['user_id' => $user->getKey()]);
    }

    /**
     * ADR-013 — `queue()`, NU `send()`: cererea HTTP rulează integral într-o tranzacție
     * deschisă (`SetSessionContext`), deci o livrare SMTP sincronă ar ține tranzacția
     * deschisă cât ține rețeaua. `after_commit = true` pe conexiunea Redis (ADR-014 pct. 6)
     * face ca jobul să nu fie ridicat înainte ca rândul `memberships` să existe cu adevărat.
     *
     * Transportul e `App\Mail\Transport\DemoInterceptingTransport`, decorat o singură dată
     * în `InterceptingMailManager` — nimic de cerut, nimic de configurat aici: ACESTA e
     * fluxul pentru care interceptarea s-a construit în Faza 4 (§22.3, „o adresă arbitrară,
     * tastată de vizitator, devine destinatar").
     */
    private function send(Membership $membership, string $email, string $role, string $plainToken, User $actor): void
    {
        $tenant = app('tenant');

        Mail::to($email)->queue(new MemberInvitationMail(
            workspaceName: $tenant->name,
            workspaceSlug: $tenant->slug,
            invitedByName: $actor->name,
            roleName: $role !== '' ? $role : Permissions::VIEWER,
            acceptUrl: url("/invitations/{$tenant->slug}/{$plainToken}"),
            expiresInDays: InvitationToken::VALID_FOR_DAYS,
        ));
    }
}
