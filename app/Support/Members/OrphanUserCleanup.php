<?php

namespace App\Support\Members;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extras din `App\Jobs\System\PruneExpiredInvitationsJob` (GDPR-09) ca să poată fi refolosit,
 * NESCHIMBAT, de `App\Jobs\System\PurgeCanceledTenantsJob` (GDPR-01, ADR-012): amândouă
 * joburile ajung, pe căi diferite, la același eveniment — un user care tocmai a pierdut
 * singura lui legătură cu un tenant (invitație revocată / tenant purjat) — și trebuie să
 * decidă identic dacă rândul `users` a rămas orfan.
 *
 * ACTORUL e un `User` NEPERSISTAT: nu există aici — apelantul decide contextul acțiunii
 * (`RevokeInvitationAction` pentru invitații, ștergerea directă a tenantului pentru purjare);
 * helper-ul de față se ocupă STRICT de verificarea „mai are userul vreo membership, ORIUNDE"
 * și de ștergerea rândului `users`, nu de ce s-a întâmplat înainte.
 *
 * DE CE un context NOU, cu `app.user_id` = ID-ul CANDIDATULUI (`TenantContext::openFor()`):
 * un actor UMAN ține `app.user_id` legat de EL ÎNSUȘI pe toată cererea, iar politica RLS
 * `membership_visibility` a lui `memberships` (`user_id = app.user_id OR tenant_id =
 * app.tenant_id`) i-ar ascunde membership-urile ALTUI user din alte tenanți — exact ce
 * face „userul ăsta n-are niciun alt membership" imposibil de verificat cross-tenant pentru
 * un actor uman (limitare semnalată în docblock-ul `RevokeInvitationAction`). Un job de
 * SISTEM n-are această constrângere: poate deschide un context NOU, cu `app.user_id` =
 * ID-ul candidatului verificat — aceeași poartă pe care se bazează
 * `Membership::forCurrentUserAcrossTenants()` pentru comutatorul de workspace — și atunci
 * politica face vizibile TOATE membership-urile acelui user, din orice tenant, active SAU
 * dezactivate (ADR-011 nu șterge niciodată fizic un membership — un rând `deactivated`
 * numără la fel ca unul `active` pentru „nu e orfan").
 *
 * `DB::table()`, nu `Membership::query()`: scope-ul de tenant al Eloquent CERE un
 * `app.tenant_id` legat (altfel `TenantContextMissingException`), pe care acest context
 * deliberat nu-l are. `DB::table()` oricum nu e supus scope-urilor Eloquent — SINGURUL loc
 * cu `withoutGlobalScope(TenantScope::class)` rămâne neatins, garda arhitecturală de pe el
 * nu se strică.
 *
 * Ștergerea rândului `users` mai verifică, o singură dată, referințele RĂMASE prin FK-urile
 * `RESTRICT` implicite ale schemei (`deals.owner_user_id`/`created_by`, `orders.*`,
 * `contacts.created_by`, `accounts.created_by`, `payments.created_by`, etc.) — imposibil de
 * interogat direct cross-tenant (RLS le ascunde fără un `app.tenant_id` explicit, iar userul
 * candidat n-a avut niciodată un membership ACTIV cu care să fi produs vreun rând acolo), dar
 * verificările de integritate referențială din PostgreSQL NU sunt supuse RLS-ului (documentat:
 * constrângerile FK „always bypass row security to ensure that data integrity is
 * maintained"). `DELETE` eșuat cu `23503` (foreign_key_violation) => păstrăm rândul și logăm,
 * în loc să blocăm restul lotului. `memberships.user_id` e `cascadeOnDelete()`, de-aia
 * verificarea LUI e făcută explicit, ÎNAINTE, în cod: o cascadă tăcută ar șterge un
 * membership dintr-un tenant neprocesat încă în aceeași rulare, fără nicio eroare care s-o
 * semnaleze.
 */
final class OrphanUserCleanup
{
    /** SQLSTATE Postgres pentru `foreign_key_violation`. */
    private const FOREIGN_KEY_VIOLATION = '23503';

    /**
     * @param  string  $sourceJob  numele clasei apelante, doar pentru mesajul de log —
     *                             ambii apelanți pot lăsa un rând `users` blocat pentru
     *                             motive diferite (invitație expirată / tenant purjat), iar
     *                             un operator care citește logul trebuie să știe care.
     * @return bool true dacă rândul `users` a fost șters, false dacă a fost păstrat
     *              (mai are o membership în altă parte, sau o referință FK reziduală).
     */
    public static function deleteIfOrphan(string $userId, string $sourceJob): bool
    {
        return TenantContext::openFor($userId, function () use ($userId, $sourceJob): bool {
            $hasAnyMembership = DB::table('memberships')->where('user_id', $userId)->exists();

            if ($hasAnyMembership) {
                return false;
            }

            // Tranzacție IMBRICATĂ = savepoint: o violare de FK prinsă direct în tranzacția
            // lui `openFor()` ar lăsa-o abandonată (`25P02`, vezi `ContactErasure`), iar
            // `DB::transaction()` face rollback la savepoint înainte să re-arunce.
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $userId)->delete());

                return true;
            } catch (QueryException $e) {
                if ($e->getCode() !== self::FOREIGN_KEY_VIOLATION) {
                    throw $e;
                }

                Log::warning("{$sourceJob}: user row kept — an unexpected reference blocks deletion.", [
                    'user_id' => $userId,
                ]);

                return false;
            }
        });
    }
}
