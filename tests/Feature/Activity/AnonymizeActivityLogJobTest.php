<?php

namespace Tests\Feature\Activity;

use App\Jobs\System\AnonymizeActivityLogJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-AUD-01, specs.md §17.2 — retenția lunară: rândurile mai vechi de 36 de luni, pentru
 * entități PERSONALE (Contact, User), au `old_values`/`new_values` anonimizate — cheile
 * (numele câmpurilor) rămân, valorile devin `[anonymized]`. Rândul NU se șterge.
 */
class AnonymizeActivityLogJobTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
    }

    public function test_old_contact_rows_are_anonymized_but_recent_and_non_personal_rows_are_not(): void
    {
        [$oldContactLogId, $recentContactLogId, $oldAccountLogId] = TenantContext::run($this->marlin, function (): array {
            $old = now()->subMonths(40);
            $recent = now()->subMonths(2);

            $oldContact = $this->insertLog(Contact::class, $old, ['email' => 'jane@old.example', 'first_name' => 'Jane']);
            $recentContact = $this->insertLog(Contact::class, $recent, ['email' => 'jane@recent.example']);
            $oldAccount = $this->insertLog(Account::class, $old, ['name' => 'Acme Corp']);

            return [$oldContact, $recentContact, $oldAccount];
        });

        (new AnonymizeActivityLogJob)->handle();

        TenantContext::run($this->marlin, function () use ($oldContactLogId, $recentContactLogId, $oldAccountLogId): void {
            $oldContact = ActivityLog::query()->findOrFail($oldContactLogId);
            $this->assertSame(['email' => '[anonymized]', 'first_name' => '[anonymized]'], $oldContact->new_values);

            $recentContact = ActivityLog::query()->findOrFail($recentContactLogId);
            $this->assertSame(['email' => 'jane@recent.example'], $recentContact->new_values);

            $oldAccount = ActivityLog::query()->findOrFail($oldAccountLogId);
            $this->assertSame(['name' => 'Acme Corp'], $oldAccount->new_values);
        });
    }

    /**
     * Garda „mutantă" pentru bucla peste tenanți: dacă cineva ar opri
     * `Tenant::query()->eachById()` după primul tenant găsit — sau ar înlocui-o cu o
     * interogare care procesează un singur tenant — NICIUN test din acest fișier l-ar
     * prinde, fiindcă toate folosesc un singur tenant (`$this->marlin`). Doi tenanți,
     * fiecare cu un rând vechi de Contact, dovedesc explicit că jobul anonimizează AMBII
     * într-o singură rulare, nu doar primul din iterație.
     */
    public function test_the_job_anonymizes_old_rows_across_every_tenant_in_a_single_run(): void
    {
        $globex = $this->makeTenant('globex', 'Globex Industrial LLC');
        $globexOwner = $this->makeMember($globex, 'owner@globex.throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $marlinLogId = TenantContext::run(
            $this->marlin,
            fn () => $this->insertLog(Contact::class, now()->subMonths(40), ['email' => 'marlin@old.example']),
        );

        $globexLogId = TenantContext::run(
            $globex,
            fn () => $this->insertLog(Contact::class, now()->subMonths(40), ['email' => 'globex@old.example'], $globex, $globexOwner),
        );

        (new AnonymizeActivityLogJob)->handle();

        TenantContext::run($this->marlin, function () use ($marlinLogId): void {
            $log = ActivityLog::query()->findOrFail($marlinLogId);
            $this->assertSame(['email' => '[anonymized]'], $log->new_values);
        });

        TenantContext::run($globex, function () use ($globexLogId): void {
            $log = ActivityLog::query()->findOrFail($globexLogId);
            $this->assertSame(['email' => '[anonymized]'], $log->new_values);
        });
    }

    public function test_running_the_job_twice_is_idempotent(): void
    {
        $logId = TenantContext::run(
            $this->marlin,
            fn () => $this->insertLog(Contact::class, now()->subMonths(40), ['email' => 'jane@old.example']),
        );

        (new AnonymizeActivityLogJob)->handle();
        (new AnonymizeActivityLogJob)->handle();

        TenantContext::run($this->marlin, function () use ($logId): void {
            $log = ActivityLog::query()->findOrFail($logId);
            $this->assertSame(['email' => '[anonymized]'], $log->new_values);
        });
    }

    /**
     * GDPR-09 (P3, audit 2026-09-23) — `Membership` e un tip MIXT (vezi docblock-ul
     * jobului): doar `created`/`deleted` (invitare/revocare) scriu emailul invitatului.
     * `role_changed` și `updated` (dezactivare) NU au nimic personal și NU trebuie
     * mascate, oricât de vechi ar fi — altfel s-ar distruge fără motiv un istoric de
     * business (US-AUD-01).
     */
    public function test_old_membership_revocation_rows_are_anonymized_but_recent_and_non_personal_rows_are_not(): void
    {
        [$oldRevocationId, $recentRevocationId, $oldRoleChangeId, $oldDeactivationId] = TenantContext::run($this->marlin, function (): array {
            $old = now()->subMonths(40);
            $recent = now()->subMonths(2);

            // `App\Actions\Members\RevokeInvitationAction` — emailul stă în `old_values`,
            // `new_values` rămâne `null` (rândul șters).
            $oldRevocation = $this->insertLog(
                Membership::class,
                $old,
                newValues: null,
                oldValues: ['status' => Membership::STATUS_PENDING, 'email' => 'invited@old.example', 'role' => Permissions::AGENT],
                action: 'deleted',
            );

            $recentRevocation = $this->insertLog(
                Membership::class,
                $recent,
                newValues: null,
                oldValues: ['status' => Membership::STATUS_PENDING, 'email' => 'invited@recent.example', 'role' => Permissions::AGENT],
                action: 'deleted',
            );

            // `App\Actions\Members\UpdateMemberRoleAction` — niciun email, oricât de vechi.
            $oldRoleChange = $this->insertLog(
                Membership::class,
                $old,
                newValues: ['role' => Permissions::VIEWER],
                oldValues: ['role' => Permissions::MANAGER],
                action: 'role_changed',
            );

            // Dezactivare (`MembersController`) — niciun email, `deactivated_by` e un ID.
            $oldDeactivation = $this->insertLog(
                Membership::class,
                $old,
                newValues: ['status' => Membership::STATUS_DEACTIVATED, 'deactivated_by' => $this->owner->getKey()],
                oldValues: ['status' => Membership::STATUS_ACTIVE],
                action: 'updated',
            );

            return [$oldRevocation, $recentRevocation, $oldRoleChange, $oldDeactivation];
        });

        (new AnonymizeActivityLogJob)->handle();

        TenantContext::run($this->marlin, function () use ($oldRevocationId, $recentRevocationId, $oldRoleChangeId, $oldDeactivationId): void {
            // `assertEquals`, NU `assertSame`, pe rândurile cu 2+ chei: Postgres
            // canonicalizează ordinea cheilor unui `jsonb` (lungime, apoi lexicografic),
            // nu ordinea de inserare — `===` pe array-uri PHP e sensibil la ordine,
            // `==`/`assertEquals` nu, iar ordinea cheilor n-are nicio semnificație de
            // business aici.
            $oldRevocation = ActivityLog::query()->findOrFail($oldRevocationId);
            $this->assertEquals(
                ['status' => '[anonymized]', 'email' => '[anonymized]', 'role' => '[anonymized]'],
                $oldRevocation->old_values,
            );
            $this->assertNull($oldRevocation->new_values);

            $recentRevocation = ActivityLog::query()->findOrFail($recentRevocationId);
            $this->assertEquals(
                ['status' => Membership::STATUS_PENDING, 'email' => 'invited@recent.example', 'role' => Permissions::AGENT],
                $recentRevocation->old_values,
            );

            $oldRoleChange = ActivityLog::query()->findOrFail($oldRoleChangeId);
            $this->assertSame(['role' => Permissions::MANAGER], $oldRoleChange->old_values);
            $this->assertSame(['role' => Permissions::VIEWER], $oldRoleChange->new_values);

            $oldDeactivation = ActivityLog::query()->findOrFail($oldDeactivationId);
            $this->assertSame(['status' => Membership::STATUS_ACTIVE], $oldDeactivation->old_values);
            $this->assertEquals(
                ['status' => Membership::STATUS_DEACTIVATED, 'deactivated_by' => $this->owner->getKey()],
                $oldDeactivation->new_values,
            );
        });
    }

    /**
     * Cross-tenant, sub RLS: o revocare veche a lui `globex` se maschează în ACEEAȘI
     * rulare ca a lui `marlin`, fiecare vizibilă doar prin propriul `TenantContext` — nu
     * doar tenantul implicit al fișierului (aceeași gardă „mutantă" ca la Contact, de
     * mai sus, dar pe tipul nou adăugat).
     */
    public function test_old_membership_revocation_rows_are_anonymized_across_every_tenant(): void
    {
        $globex = $this->makeTenant('globex', 'Globex Industrial LLC');
        $globexOwner = $this->makeMember($globex, 'owner@globex.throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $marlinLogId = TenantContext::run(
            $this->marlin,
            fn () => $this->insertLog(
                Membership::class,
                now()->subMonths(40),
                newValues: null,
                oldValues: ['status' => Membership::STATUS_PENDING, 'email' => 'marlin-invited@old.example', 'role' => Permissions::AGENT],
                action: 'deleted',
            ),
        );

        $globexLogId = TenantContext::run(
            $globex,
            fn () => $this->insertLog(
                Membership::class,
                now()->subMonths(40),
                newValues: null,
                oldValues: ['status' => Membership::STATUS_PENDING, 'email' => 'globex-invited@old.example', 'role' => Permissions::AGENT],
                action: 'deleted',
                tenant: $globex,
                user: $globexOwner,
            ),
        );

        (new AnonymizeActivityLogJob)->handle();

        TenantContext::run($this->marlin, function () use ($marlinLogId): void {
            $log = ActivityLog::query()->findOrFail($marlinLogId);
            $this->assertSame('[anonymized]', $log->old_values['email']);
        });

        TenantContext::run($globex, function () use ($globexLogId): void {
            $log = ActivityLog::query()->findOrFail($globexLogId);
            $this->assertSame('[anonymized]', $log->old_values['email']);
        });
    }

    /**
     * `$tenant`/`$user` opționale, implicit `$this->marlin`/`$this->owner` — vezi
     * `test_the_job_anonymizes_old_rows_across_every_tenant_in_a_single_run()` pentru
     * singurul apelant care le folosește explicit, pe un al doilea tenant.
     *
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    private function insertLog(
        string $auditableType,
        Carbon $createdAt,
        ?array $newValues,
        ?Tenant $tenant = null,
        ?User $user = null,
        ?array $oldValues = null,
        string $action = 'updated',
    ): string {
        $tenant ??= $this->marlin;
        $user ??= $this->owner;
        $id = strtolower((string) Str::ulid());

        DB::table('activity_log')->insert([
            'id' => $id,
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => strtolower((string) Str::ulid()),
            'old_values' => $oldValues === null ? null : json_encode($oldValues),
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PestTest/1.0',
            'created_at' => $createdAt,
        ]);

        return $id;
    }
}
