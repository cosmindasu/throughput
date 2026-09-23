<?php

namespace App\Jobs\System;

use App\Actions\Members\RevokeInvitationAction;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GDPR-09 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`, P3) — invitațiile
 * expirate/neacceptate nu erau purjate automat: `RevokeInvitationAction` există, dar e
 * declanșată STRICT manual, din ecranul de Members (`MembersController`). O invitație pe
 * care nimeni n-a revocat-o rămânea `pending` la nesfârșit, cu emailul invitatului și un
 * rând `users` orfan (fără parolă utilizabilă, fără niciun membership activ) atașate.
 *
 * Job de SISTEM (`.ai/rules/tenancy.md`, „Două familii de joburi"): fără tenant propriu,
 * iterează tenanții explicit, cu un context per tenant — structurat ca `PruneSentEmailsJob`.
 *
 * REFOLOSEȘTE `RevokeInvitationAction` NESCHIMBATĂ (fișier în afara acestui lot): aceeași
 * blocare de rând sub `lockForUpdate()`, aceeași eliminare a rolului per tenant, ACELAȘI
 * rând de `activity_log` ca la o revocare manuală — un cititor al jurnalului vede „membership
 * pending șters", nu un flux paralel, needocumentat, care ar duplica logica de reverificare
 * sub blocare (revocată/retrimisă/expirată încă o dată, între citirea de mai jos și lock).
 *
 * ACTORUL e un `User` NEPERSISTAT (`new User()`): `RevokeInvitationAction::execute()` cere
 * un `User $actor` nenulabil doar ca să scrie `$actor->getKey()` în `activity_log.user_id`.
 * Un `User` nesalvat n-are cheie (`getKey()` întoarce `null`), deci rândul de jurnal capătă
 * `user_id = null` — convenția DEJA folosită de acest proiect pentru acțiuni de sistem fără
 * operator uman (`App\Listeners\Activity\WriteActivityLogEntry`, BR-BILL-02: „`user_id = null`
 * ... vine gratuit, din context, nu dintr-un caz special"). IP-ul și user-agentul sunt
 * marcaje explicite (`system` / numele acestei clase), nu o adresă reală sau simulată —
 * cererea nu vine de la niciun client HTTP.
 *
 * RÂNDUL `users` ORFAN — exact limitarea semnalată în docblock-ul `RevokeInvitationAction`
 * (liniile 24-29): „a decide «utilizatorul ăsta n-are niciun alt membership» ar cere o
 * interogare cross-tenant peste `memberships`, pe care politica RLS a tabelei o face
 * imposibilă pentru un ALT utilizator decât cel autentificat (`app.user_id`)". Adevărat
 * pentru un ACTOR uman, care ține `app.user_id` legat de EL ÎNSUȘI pe toată cererea. Un job
 * de sistem nu are această constrângere: poate deschide un context NOU, cu `app.user_id`
 * = ID-ul CANDIDATULUI verificat (`TenantContext::openFor()`, aceeași poartă pe care se
 * bazează `Membership::forCurrentUserAcrossTenants()` pentru comutatorul de workspace) —
 * politica `membership_visibility` (`user_id = app.user_id OR tenant_id = app.tenant_id`)
 * face vizibile ATUNCI toate membership-urile acelui user, din orice tenant, fără
 * `withoutGlobalScope` (interogarea de mai jos e `DB::table()`, care oricum nu e supusă
 * scope-urilor Eloquent — SINGURUL loc cu `withoutGlobalScope(TenantScope::class)` rămâne
 * neatins, garda arhitecturală de pe el nu se strică).
 *
 * Ștergerea rândului `users` mai verifică, o singură dată, referințele RĂMASE prin FK-urile
 * `RESTRICT` implicite ale schemei (`deals.owner_user_id`/`created_by`, `orders.*`,
 * `contacts.created_by`, `accounts.created_by`, `payments.created_by`, etc.) — imposibil de
 * interogat direct cross-tenant (RLS le ascunde fără un `app.tenant_id` explicit, iar userul
 * candidat n-a avut niciodată un membership ACTIV cu care să fi produs vreun rând acolo), dar
 * verificările de integritate referențială din PostgreSQL NU sunt supuse RLS-ului (documentat:
 * constrângerile FK „always bypass row security to ensure that data integrity is maintained").
 * `DELETE` eșuat cu `23503` (foreign_key_violation) => păstrăm rândul și logăm, în loc să
 * blocăm restul lotului. `memberships.user_id` e `cascadeOnDelete()`, de-aia verificarea LUI
 * e făcută explicit, ÎNAINTE, în cod: o cascadă tăcută ar șterge un membership dintr-un tenant
 * neprocesat încă în această rulare, fără nicio eroare care s-o semnaleze.
 *
 * Prag de retenție: 30 de zile de la expirare — CONSTANTĂ DE CLASĂ, nu cheie de config:
 * `config/throughput.php` nu e în felia acestui lot. Propunere pentru integrare:
 * `throughput.limits.expired_invitation_retention_days` (env `EXPIRED_INVITATION_RETENTION_DAYS`).
 */
class PruneExpiredInvitationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    private const RETENTION_DAYS = 30;

    private const CHUNK_SIZE = 200;

    /** SQLSTATE Postgres pentru `foreign_key_violation` — vezi docblock-ul clasei. */
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(self::RETENTION_DAYS);
        $action = new RevokeInvitationAction;
        $systemActor = new User;
        $systemRequest = self::systemRequest();

        Tenant::query()->eachById(function (Tenant $tenant) use ($cutoff, $action, $systemActor, $systemRequest): void {
            $candidateUserIds = TenantContext::run(
                $tenant,
                fn (): array => $this->revokeExpiredForCurrentTenant($cutoff, $action, $systemActor, $systemRequest),
            );

            foreach ($candidateUserIds as $userId) {
                $this->deleteIfOrphan($userId);
            }
        });
    }

    /**
     * Revocă, în TENANTUL CURENT (contextul e deja deschis de apelant), toate invitațiile
     * `pending` expirate de mai mult de `RETENTION_DAYS` zile.
     *
     * `chunkById`, nu `chunk()`/`get()`: fiecare `execute()` ȘTERGE rândul procesat, deci
     * rândul iese din `where('status', PENDING)` — o paginare pe OFFSET ar sări rânduri la
     * fiecare tranșă (aceeași capcană documentată în `PruneExpiredExportsJob`). `chunkById`
     * se ține de cursorul pe `id`, neafectat de ștergeri.
     *
     * @return list<string> id-urile userilor ale căror invitații au fost revocate ACUM —
     *                      candidați pentru verificarea de „orfan" din `deleteIfOrphan()`.
     */
    private function revokeExpiredForCurrentTenant(
        CarbonImmutable $cutoff,
        RevokeInvitationAction $action,
        User $systemActor,
        Request $systemRequest,
    ): array {
        $candidateUserIds = [];

        Membership::query()
            ->where('status', Membership::STATUS_PENDING)
            ->where('invitation_expires_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($memberships) use ($action, $systemActor, $systemRequest, &$candidateUserIds): void {
                foreach ($memberships as $membership) {
                    // Capturat ÎNAINTE de `execute()`: acțiunea șterge rândul, `user_id`
                    // n-ar mai fi lizibil de pe el după.
                    $candidateUserIds[] = $membership->user_id;

                    // Reverifică sub blocare (retrimisă/acceptată/deja revocată între
                    // citirea de mai sus și acum) — exact ce face acțiunea pentru fluxul
                    // manual; un no-op tăcut dacă starea nu mai e `pending`.
                    $action->execute($systemActor, $membership, $systemRequest);
                }
            });

        return $candidateUserIds;
    }

    /**
     * Șterge rândul `users` DOAR dacă userul n-are, ACUM, nicio altă membership (în niciun
     * tenant) și nicio altă referință în schemă — vezi docblock-ul clasei pentru cele două
     * verificări (aplicativă pe `memberships`, de integritate pe restul FK-urilor).
     */
    private function deleteIfOrphan(string $userId): void
    {
        TenantContext::openFor($userId, function () use ($userId): void {
            // `DB::table()`, nu `Membership::query()`: scope-ul de tenant al Eloquent CERE
            // un `app.tenant_id` legat (altfel `TenantContextMissingException`), pe care
            // acest context deliberat nu-l are — vezi docblock-ul clasei.
            $hasAnyMembership = DB::table('memberships')->where('user_id', $userId)->exists();

            if ($hasAnyMembership) {
                return;
            }

            // Tranzacție IMBRICATĂ = savepoint: o violare de FK prinsă direct în tranzacția
            // lui `openFor()` ar lăsa-o abandonată (`25P02`, vezi `ContactErasure`), iar
            // `DB::transaction()` face rollback la savepoint înainte să re-arunce.
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $userId)->delete());
            } catch (QueryException $e) {
                if ($e->getCode() !== self::FOREIGN_KEY_VIOLATION) {
                    throw $e;
                }

                Log::warning('PruneExpiredInvitationsJob: user row kept — an unexpected reference blocks deletion.', [
                    'user_id' => $userId,
                ]);
            }
        });
    }

    /**
     * O cerere minimală, doar ca să satisfacă tipul `Request` cerut de
     * `RevokeInvitationAction::execute()` — niciun client HTTP nu există aici. `REMOTE_ADDR`/
     * `User-Agent` sunt marcaje explicite, nu o adresă reală sau simulată.
     */
    private static function systemRequest(): Request
    {
        return Request::create('cli://prune-expired-invitations', 'GET', server: [
            'REMOTE_ADDR' => 'system',
            'HTTP_USER_AGENT' => self::class,
        ]);
    }
}
