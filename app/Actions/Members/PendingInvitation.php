<?php

namespace App\Actions\Members;

use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rezolvarea „token în clar → invitație", pentru ruta PUBLICĂ de acceptare.
 *
 * De ce URL-ul poartă și workspace-ul (`/invitations/{workspace}/{token}`), nu doar
 * tokenul: politica RLS a lui `memberships` e
 * `user_id = app.user_id OR tenant_id = app.tenant_id` (ADR-014, pct. 2). Un vizitator
 * neautentificat n-are NICIUNA dintre cele două variabile de sesiune, deci o căutare
 * „după token, oriunde" întoarce zero rânduri — nu pentru că tokenul ar fi greșit, ci
 * pentru că politica ascunde tot. Verificat, nu presupus: fără context, tabela e goală
 * pentru oricine.
 *
 * Alternativele respinse: (a) un scan pe conexiunea cu `BYPASSRLS`, rezervată explicit
 * migrațiilor (§6.2, ADR-003); (b) o politică RLS nouă cu ramură „fără context", care ar fi
 * fost al treilea apelant al lui `enableRlsWithPolicy()` și ar fi cerut un ADR ([[ADR-020]]).
 * Slug-ul în URL rezolvă problema fără să atingă niciuna: `tenants` nu are RLS (identitate
 * globală, §19.1), deci se poate citi fără context, iar restul căutării rulează ÎNĂUNTRUL
 * unui `TenantContext::run()` obișnuit — exact poarta unică din ADR-014.
 *
 * Slug-ul nu e un secret și nu e o autorizare: singurul lucru care dă acces e tokenul,
 * comparat pe hash-ul lui.
 */
final class PendingInvitation
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Membership $membership,
    ) {}

    /**
     * `null` = slug inexistent, token inexistent, sau membership care nu mai e „pending"
     * (deja acceptat, revocat, dezactivat). Apelantul face 404 pe toate — un token invalid
     * și un workspace inexistent nu trebuie distinse de un vizitator anonim (§18.5).
     *
     * Expirarea NU e tratată aici: o invitație expirată trebuie să producă un ecran care
     * SPUNE că a expirat și pe cine să întrebi, nu un 404 indistinct de o greșeală de
     * tastare. Vezi `Membership::invitation_expires_at` și `AcceptInvitationController`.
     */
    public static function resolve(string $workspaceSlug, string $plainToken): ?self
    {
        if (! InvitationToken::looksValid($plainToken)) {
            return null;
        }

        $tenant = Tenant::query()->where('slug', $workspaceSlug)->first();

        if ($tenant === null) {
            return null;
        }

        // Rolul invitatului e per tenant (`model_has_roles.tenant_id`, §7.2) — fără pasul
        // ăsta, `getRoleNames()` interoghează cu `tenant_id = null` și întoarce o colecție
        // goală, adică ecranul de acceptare ar spune „ai fost invitat ca ⟨nimic⟩".
        // `roles`/`model_has_roles` NU au RLS (identități de configurare, nu date de
        // tenant), deci citirea merge și în afara contextului de mai jos.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        $membership = TenantContext::run($tenant, fn () => Membership::query()
            ->with(['user', 'user.roles'])
            ->where('status', Membership::STATUS_PENDING)
            ->where('invitation_token', InvitationToken::hash($plainToken))
            ->first());

        return $membership === null ? null : new self($tenant, $membership);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->membership->invitation_expires_at;

        return $expiresAt === null || $expiresAt->isPast();
    }

    /**
     * Un invitat care nu și-a folosit niciodată contul (rândul `users` creat chiar de
     * invitație — vezi `InviteMemberAction`) trebuie să-și aleagă nume și parolă la
     * acceptare. Unul care e deja utilizator în altă organizație doar confirmă.
     */
    public function needsProfile(): bool
    {
        return $this->membership->user?->email_verified_at === null;
    }
}
