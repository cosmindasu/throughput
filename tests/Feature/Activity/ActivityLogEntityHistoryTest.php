<?php

namespace Tests\Feature\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-AUD-02, §17.3 — tab „History", endpoint JSON (`HistoryTab.tsx`). Gated de Policy-ul
 * ENTITĂȚII (`AccountPolicy::view()`), NU de `activity_log.view`/`view_own` — orice rol
 * care poate vedea contul îi vede și istoricul complet, indiferent CINE l-a scris.
 */
class ActivityLogEntityHistoryTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $viewer;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->account = TenantContext::run($this->marlin, function (): Account {
            $account = (new AccountFactory)->create([
                'owner_user_id' => $this->owner->getKey(),
                'created_by' => $this->owner->getKey(),
            ]);

            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'updated',
                'auditable_type' => Account::class,
                'auditable_id' => $account->getKey(),
                'old_values' => ['name' => 'Old name'],
                'new_values' => ['name' => $account->name],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);

            // Rând de un ALT tip de entitate — nu are ce căuta în răspunsul filtrat.
            ActivityLog::query()->create([
                'user_id' => $this->owner->getKey(),
                'action' => 'created',
                'auditable_type' => 'App\\Models\\Deal',
                'auditable_id' => (string) Str::ulid(),
                'new_values' => ['title' => 'Unrelated deal'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PestTest/1.0',
            ]);

            return $account;
        });
        $this->clearDatabaseTenantContext();
    }

    public function test_viewer_can_read_the_history_of_an_account_they_can_view(): void
    {
        $response = $this->actingAs($this->viewer)->getJson("/marlin/activity/entity/account/{$this->account->getKey()}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.action', 'updated');
        $response->assertJsonPath('data.0.oldValues.name', 'Old name');
        // Cheile adăugate de 858fa92 pe `ActivityLogResource`. Fără ele, ștergerea celor trei
        // câmpuri din resursă — sau a lui `with('auditable')` din controller — nu înroșea nimic:
        // `DashboardTest` le asertează pe ALTĂ resursă (`ActivityEntryResource`).
        $response->assertJsonPath('data.0.kind', 'updated');
        $response->assertJsonPath('data.0.description', 'Updated Account');
        $response->assertJsonPath('data.0.subjectName', $this->account->name);
    }

    public function test_an_unknown_entity_type_is_not_routable(): void
    {
        $this->actingAs($this->owner)->getJson("/marlin/activity/entity/invoice/{$this->account->getKey()}")->assertNotFound();
    }

    public function test_a_cross_tenant_id_is_not_found(): void
    {
        $otherTenant = $this->makeTenant('northgate', 'Northgate Supply');
        $otherOwner = $this->makeMember($otherTenant, 'owner@northgate.dev', Permissions::OWNER);

        $this->actingAs($otherOwner)
            ->getJson("/northgate/activity/entity/account/{$this->account->getKey()}")
            ->assertNotFound();
    }
}
