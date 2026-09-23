<?php

namespace Tests\Feature\Members;

use App\Jobs\System\PruneExpiredInvitationsJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GDPR-09 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`, P3) —
 * `PruneExpiredInvitationsJob`: invitațiile `pending` neacceptate, expirate de peste 30 de
 * zile (constantă de clasă, `PruneExpiredInvitationsJob::RETENTION_DAYS`), urmează fluxul
 * `RevokeInvitationAction` NESCHIMBAT, plus ștergerea rândului `users` orfan.
 *
 * Fixturile folosesc 31/29 zile — deliberat departe de graniță (nu 30/30), ca testul să nu
 * depindă de rotunjirea exactă a comparației `<`.
 */
class PruneExpiredInvitationsJobTest extends TestCase
{
    public function test_an_invitation_expired_more_than_the_threshold_is_revoked_and_its_orphan_user_deleted(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $invitee = $this->invitePendingMember($tenant, 'invitee@example.com', now()->subDays(31));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $this->assertMembershipGone($tenant, $invitee);
        $this->assertNull(User::query()->find($invitee->getKey()), 'The orphan user row (no membership anywhere) must be deleted.');
    }

    public function test_an_invitation_expired_less_than_the_threshold_is_left_intact(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $invitee = $this->invitePendingMember($tenant, 'invitee@example.com', now()->subDays(29));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $membership = TenantContext::run($tenant, fn () => Membership::query()->where('user_id', $invitee->getKey())->first());
        $this->assertNotNull($membership, 'A recently expired invitation must not be touched yet.');
        $this->assertSame(Membership::STATUS_PENDING, $membership->status);
        $this->assertNotNull(User::query()->find($invitee->getKey()));
    }

    /**
     * Invitația însăși e revocată normal (a expirat), dar userul e PARTAJAT — o membership
     * ACTIVĂ în alt tenant îl scoate din categoria „orfan".
     *
     * Exact politica `membership_visibility` (`user_id = app.user_id OR tenant_id =
     * app.tenant_id`, `.ai/rules/tenancy.md`) face posibilă această verificare cross-tenant
     * pentru un job de sistem (`TenantContext::openFor($userId, ...)`).
     */
    public function test_a_user_with_another_membership_in_a_different_tenant_is_kept(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Metal Works');

        $shared = $this->invitePendingMember($marlin, 'shared@example.com', now()->subDays(31));
        $this->makeMember($cascade, $shared->email, Permissions::AGENT, $shared);
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $this->assertMembershipGone($marlin, $shared);
        $this->assertNotNull(User::query()->find($shared->getKey()), 'A user with a membership elsewhere must never be deleted.');

        $cascadeMembership = TenantContext::run($cascade, fn () => Membership::query()->where('user_id', $shared->getKey())->first());
        $this->assertNotNull($cascadeMembership, 'The untouched membership in the other tenant must survive.');
        $this->assertSame(Membership::STATUS_ACTIVE, $cascadeMembership->status);
    }

    public function test_an_accepted_membership_is_never_touched(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $activeMember = $this->makeMember($tenant, 'active@example.com', Permissions::AGENT);
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $membership = TenantContext::run($tenant, fn () => Membership::query()->where('user_id', $activeMember->getKey())->first());
        $this->assertNotNull($membership);
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status);
        $this->assertNotNull(User::query()->find($activeMember->getKey()));
    }

    public function test_the_revocation_writes_an_activity_log_row(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $invitee = $this->invitePendingMember($tenant, 'invitee@example.com', now()->subDays(31));
        $membershipId = TenantContext::run($tenant, fn () => Membership::query()->where('user_id', $invitee->getKey())->value('id'));
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $entry = TenantContext::run($tenant, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->where('auditable_id', $membershipId)
            ->where('action', 'deleted')
            ->first());

        $this->assertNotNull($entry, 'RevokeInvitationAction must still write its activity_log row when called from the system job.');
        $this->assertNull($entry->user_id, 'No human actor — the system job uses an unpersisted User (user_id = null), same convention as automated actions elsewhere (BR-BILL-02).');
        $this->assertSame('invitee@example.com', $entry->old_values['email'] ?? null);
    }

    /**
     * Un user fără nicio membership, dar încă referit de un rând de business (`accounts.created_by`,
     * FK `RESTRICT`), nu se poate șterge: violarea de FK e prinsă și userul rămâne. Orfanul
     * următor din aceeași rulare TREBUIE totuși șters — dovada că eroarea prinsă n-a lăsat
     * tranzacția abandonată (`25P02`), capcana pe care savepoint-ul din `deleteIfOrphan()` o evită.
     */
    public function test_a_referenced_user_is_kept_and_the_next_orphan_is_still_deleted(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $referenced = $this->invitePendingMember($tenant, 'referenced@example.com', now()->subDays(31));
        $orphan = $this->invitePendingMember($tenant, 'orphan@example.com', now()->subDays(31));

        TenantContext::run($tenant, function () use ($referenced): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $referenced->getKey();
            $account->save();
        });
        $this->clearDatabaseTenantContext();

        (new PruneExpiredInvitationsJob)->handle();

        $this->assertMembershipGone($tenant, $referenced);
        $this->assertMembershipGone($tenant, $orphan);
        $this->assertNotNull(User::query()->find($referenced->getKey()), 'A user still referenced by a business row must be kept.');
        $this->assertNull(User::query()->find($orphan->getKey()), 'The caught FK violation must not abort the transaction for the next orphan.');
    }

    /**
     * `chunkById`, NU `chunk()`/`get()` — capcana documentată explicit în docblock-ul
     * jobului („aceeași capcană documentată în PruneExpiredExportsJob"): fiecare
     * `execute()` ȘTERGE rândul procesat, deci o paginare pe OFFSET ar sări rânduri la
     * fiecare tranșă. NICIUN test din acest fișier avea mai mult de 2 invitații expirate
     * deodată — cu atât de puține, o regresie tăcută la `chunk()`/`get()` ar fi trecut la
     * fel de verde. Aici, 210 > `PruneExpiredInvitationsJob::CHUNK_SIZE` (200): dacă
     * paginarea ar fi pe offset, a doua tranșă ar sări aproximativ jumătate din rândurile
     * rămase, iar cel puțin unele membership-uri/useri ar supraviețui rulării.
     *
     * Insert BRUT (ca `ContactBulkOperationTest::bulkInsertContacts()`), fără roluri —
     * jobul nu citește rolurile, doar existența rândului `memberships`.
     */
    public function test_more_invitations_than_the_chunk_size_are_all_revoked_in_one_run(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();

        $count = 210;
        $userIds = $this->bulkInsertExpiredInvitations($tenant, $count, now()->subDays(31));

        (new PruneExpiredInvitationsJob)->handle();

        $remaining = TenantContext::run($tenant, fn () => Membership::query()->count());
        $this->assertSame(0, $remaining, 'chunkById trebuie să parcurgă TOATE tranșele, nu doar prima — o paginare pe offset ar sări jumătate din rânduri.');

        $survivingUsers = User::query()->whereIn('id', $userIds)->count();
        $this->assertSame(0, $survivingUsers, 'Toți userii orfani, din toate tranșele, trebuie șterși — nu doar cei din primul chunk.');
    }

    private function assertMembershipGone(Tenant $tenant, User $user): void
    {
        $exists = TenantContext::run($tenant, fn () => Membership::query()->where('user_id', $user->getKey())->exists());
        $this->assertFalse($exists, 'The expired pending membership must have been revoked (deleted), like a manual RevokeInvitationAction.');
    }

    /**
     * Réplique l'état minimal produit par `InviteMemberAction` (sans HTTP/mail) : un `User`
     * réservé + un `Membership` `pending` avec token et expiration explicites.
     */
    private function invitePendingMember(Tenant $tenant, string $email, Carbon $expiresAt): User
    {
        $user = User::query()->create([
            'name' => 'Invited User',
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
        ]);

        TenantContext::run($tenant, function () use ($tenant, $user, $expiresAt): void {
            Membership::query()->create([
                'user_id' => $user->getKey(),
                'status' => Membership::STATUS_PENDING,
                'invitation_token' => hash('sha256', Str::random(32)),
                'invitation_expires_at' => $expiresAt,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
            $user->assignRole(Permissions::AGENT);
        });

        return $user;
    }

    /**
     * @return list<string> id-urile userilor creați, ca `RETENTION_DAYS::assertSame()` să
     *                      poată verifica ștergerea lor ULTERIOR, fără să mai țină și
     *                      obiectele `User` (210 modele Eloquent, inutil).
     */
    private function bulkInsertExpiredInvitations(Tenant $tenant, int $count, Carbon $expiresAt): array
    {
        $now = now();
        $userIds = [];
        $userRows = [];

        for ($i = 0; $i < $count; $i++) {
            $userIds[] = $id = (string) Str::ulid();
            $userRows[] = [
                'id' => $id,
                'name' => 'Invited User '.$i,
                'email' => "invited-{$i}-".Str::random(8).'@chunking-test.example',
                'password' => Hash::make(Str::random(64)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insert($userRows);

        TenantContext::run($tenant, function () use ($tenant, $userIds, $expiresAt, $now): void {
            $membershipRows = [];

            foreach ($userIds as $id) {
                $membershipRows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $id,
                    'status' => Membership::STATUS_PENDING,
                    'invitation_token' => hash('sha256', Str::random(32)),
                    'invitation_expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('memberships')->insert($membershipRows);
        });

        return $userIds;
    }
}
