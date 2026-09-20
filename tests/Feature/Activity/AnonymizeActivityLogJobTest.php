<?php

namespace Tests\Feature\Activity;

use App\Jobs\System\AnonymizeActivityLogJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
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

    /** @param  array<string, mixed>  $newValues */
    private function insertLog(string $auditableType, Carbon $createdAt, array $newValues): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('activity_log')->insert([
            'id' => $id,
            'tenant_id' => $this->marlin->getKey(),
            'user_id' => $this->owner->getKey(),
            'action' => 'updated',
            'auditable_type' => $auditableType,
            'auditable_id' => strtolower((string) Str::ulid()),
            'old_values' => null,
            'new_values' => json_encode($newValues),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PestTest/1.0',
            'created_at' => $createdAt,
        ]);

        return $id;
    }
}
