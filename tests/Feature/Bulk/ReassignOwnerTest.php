<?php

namespace Tests\Feature\Bulk;

use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * US-BULK-01, §13.2/§13.3/§13.4 — reasignare de owner în masă pe Accounts și Deals, cap-coadă:
 * dispecerizare HTTP → job planificator → chunk-uri → `Bus::batch()` → stare terminală. RBAC
 * pe cele 4 roluri (BR-BULK-02/03), plafonul de rânduri al Agentului, pragul de confirmare
 * (verificat separat, `BulkConfirmationThresholdTest`), plafonul absolut DEMO_MODE, și
 * distincția „ids" (checkbox de pagină) vs. „selectAllMatching" (tot filtrul, §13.1).
 */
class ReassignOwnerTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    private User $newOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->newOwner = $this->makeMember($this->marlin, 'demo.new-owner@throughput.dev', Permissions::MANAGER);

        $this->clearDatabaseTenantContext();
    }

    public function test_owner_can_reassign_accounts_matching_the_current_filter(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(3)->create([
                'created_by' => $this->owner->getKey(),
                'owner_user_id' => $this->agent->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);
            (new AccountFactory)->count(2)->create([
                'created_by' => $this->owner->getKey(),
                'owner_user_id' => $this->manager->getKey(),
                'status' => Account::STATUS_INACTIVE,
            ]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post(
            '/marlin/accounts/bulk/reassign-owner?filter[status]=active',
            ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(3, $operation->total_rows);
        $this->assertSame('reassign_owner', $operation->action);

        $this->drainBulkQueue();

        $this->assertOperationStatus($operation, 'completed');

        $activeOwners = TenantContext::run($this->marlin, fn () => Account::query()->where('status', Account::STATUS_ACTIVE)->pluck('owner_user_id')->unique()->all());
        $this->assertSame([$this->newOwner->getKey()], $activeOwners);

        // Rândurile din AFARA filtrului (inactive) rămân neatinse.
        $inactiveOwners = TenantContext::run($this->marlin, fn () => Account::query()->where('status', Account::STATUS_INACTIVE)->pluck('owner_user_id')->unique()->all());
        $this->assertSame([$this->manager->getKey()], $inactiveOwners);
    }

    /**
     * BR-BULK-02 — chiar dacă Agentul schimbă filtrul pe „All accounts" (vede tot tenantul),
     * operația de scriere rămâne restrânsă la subsetul propriu — verificat ATÂT la numărul
     * capturat la dispatch (`total_rows`), CÂT ȘI la ce ajunge efectiv modificat.
     */
    public function test_agent_can_only_reassign_accounts_they_own_even_when_the_filter_shows_more(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(2)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->agent->getKey()]);
            (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->manager->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post(
            '/marlin/accounts/bulk/reassign-owner?filter[owner]=all',
            ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows, 'Filtrul „all" arată 5 conturi, dar Agentul deține doar 2.');

        $this->drainBulkQueue();

        $managerOwned = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->manager->getKey())->count());
        $this->assertSame(3, $managerOwned, 'Conturile managerului nu trebuiau atinse.');

        $reassigned = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $this->assertSame(2, $reassigned);
    }

    public function test_viewer_cannot_reassign_accounts(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)
            ->post('/marlin/accounts/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertForbidden();

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    /**
     * §13.1 — checkbox de pagină curentă (`ids`), NU tot filtrul: doar rândurile explicit
     * bifate se schimbă, restul rămâne neatins chiar dacă ar corespunde aceluiași filtru.
     */
    public function test_the_explicit_ids_mode_only_touches_the_selected_rows(): void
    {
        $ids = TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(5)
            ->create(['created_by' => $this->owner->getKey()])
            ->pluck('id')
            ->all());
        $this->clearDatabaseTenantContext();

        $targetIds = array_slice($ids, 0, 2);

        $response = $this->actingAs($this->owner)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => false,
            'ids' => $targetIds,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();

        $reassignedCount = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $this->assertSame(2, $reassignedCount);

        $reassignedIds = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->pluck('id')->sort()->values()->all());
        sort($targetIds);
        $this->assertSame($targetIds, $reassignedIds);
    }

    public function test_an_agent_over_their_row_cap_is_refused_before_any_operation_is_created(): void
    {
        config(['throughput.limits.bulk_agent_row_cap' => 3]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(5)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $this->agent->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)
            ->post('/marlin/accounts/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    public function test_an_operation_over_the_absolute_demo_cap_is_refused(): void
    {
        config(['throughput.demo.mode' => true, 'throughput.limits.bulk_max_rows' => 3]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(5)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post('/marlin/accounts/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    /**
     * P3 (code review) — test lipsă: un Agent trimite `ids` EXPLICIT care includ conturi
     * ale altui owner. `DispatchBulkOperationAction` aplică `scopeToOwnRecords()` la fel
     * pe modul „ids" ca pe „selectAllMatching" — nu e un refuz, e o restrângere tăcută a
     * selecției la subsetul permis: doar rândurile proprii se procesează, restul rămân
     * neatinse.
     */
    public function test_an_agent_sending_explicit_ids_including_another_owners_accounts_only_touches_their_own(): void
    {
        $ownIds = TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(2)
            ->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->agent->getKey()])
            ->pluck('id')->all());
        $otherIds = TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(2)
            ->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->manager->getKey()])
            ->pluck('id')->all());
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => false,
            'ids' => [...$ownIds, ...$otherIds],
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows, 'Doar cele 2 conturi proprii intră în operație, chiar dacă au fost trimise 4 id-uri.');

        $this->drainBulkQueue();

        sort($ownIds);
        $reassigned = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $this->newOwner->getKey())->pluck('id')->sort()->values()->all());
        $this->assertSame($ownIds, $reassigned);

        $managerStillOwns = TenantContext::run($this->marlin, fn () => Account::query()->whereIn('id', $otherIds)->where('owner_user_id', $this->manager->getKey())->count());
        $this->assertSame(2, $managerStillOwns, 'Conturile altui owner, trimise explicit în ids, rămân neatinse.');
    }

    /**
     * P2-002 (code review), FR-BULK-01/plan §9 — pragul de confirmare
     * (`BulkConfirmationThreshold`) era citit DOAR de props-ul dialogului din React,
     * niciodată verificat pe server: `DispatchBulkOperationAction` pornea operația
     * indiferent de câte rânduri atingea. Manager: prag 1.000 (`ABSOLUTE_CAP`, fără
     * plafon de rol); insert BRUT, nu factory — un factory cu 1.001 inserturi
     * individuale ar încetini inutil suita, iar codul verificat (`BulkMatchingRowCount`)
     * e doar un `COUNT(*)`, indiferent cum au ajuns rândurile acolo.
     */
    public function test_a_manager_reassignment_above_the_confirmation_threshold_is_refused_without_the_confirmed_flag(): void
    {
        $this->bulkInsertAccounts(1001, $this->manager->getKey());

        $this->actingAs($this->manager)
            ->post('/marlin/accounts/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    public function test_a_manager_reassignment_above_the_confirmation_threshold_starts_once_confirmed(): void
    {
        $this->bulkInsertAccounts(1001, $this->manager->getKey());

        $response = $this->actingAs($this->manager)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
            'confirmed' => true,
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1001, $operation->total_rows);
    }

    public function test_a_manager_reassignment_under_the_confirmation_threshold_starts_without_confirmation(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(5)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->manager)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(5, $operation->total_rows);
    }

    /**
     * Aceleași trei cazuri, pe Agent: pragul e 125 (25% din plafonul de rol de 500,
     * BR-BULK-02) — sub plafonul de 500 rânduri, deci refuzul e STRICT despre lipsa
     * `confirmed`, nu despre plafonul de rol (verificat separat,
     * `test_an_agent_over_their_row_cap_is_refused_before_any_operation_is_created`).
     */
    public function test_an_agent_reassignment_above_the_confirmation_threshold_is_refused_without_the_confirmed_flag(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(126)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $this->agent->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)
            ->post('/marlin/accounts/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    public function test_an_agent_reassignment_above_the_confirmation_threshold_starts_once_confirmed(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(126)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $this->agent->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
            'confirmed' => true,
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(126, $operation->total_rows);
    }

    public function test_an_agent_reassignment_under_the_confirmation_threshold_starts_without_confirmation(): void
    {
        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(5)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $this->agent->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/accounts/bulk/reassign-owner', [
            'selectAllMatching' => true,
            'owner_user_id' => $this->newOwner->getKey(),
        ]);

        $operation = $this->soleOperation('accounts');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(5, $operation->total_rows);
    }

    public function test_manager_can_reassign_deal_owners(): void
    {
        [$account, $stage] = $this->dealFixture();

        TenantContext::run($this->marlin, function () use ($account, $stage): void {
            $this->makeDeal('Deal one', $this->agent, $account, $stage);
            $this->makeDeal('Deal two', $this->agent, $account, $stage);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->manager)->post(
            '/marlin/deals/bulk/reassign-owner',
            ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('deals');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();

        $reassigned = TenantContext::run($this->marlin, fn () => Deal::query()->where('owner_user_id', $this->newOwner->getKey())->count());
        $this->assertSame(2, $reassigned);
    }

    /**
     * `deals.change_owner` există doar la Owner/Manager în catalog (`Permissions::forRoles()`,
     * decizie deja existentă înaintea acestui pachet) — un Agent nu poate reasigna owner-ul
     * unui deal, nici individual, nici în masă. Verificat chiar pe un deal pe care îl deține.
     */
    public function test_agent_cannot_reassign_deal_owners_even_on_their_own_deals(): void
    {
        [$account, $stage] = $this->dealFixture();

        TenantContext::run($this->marlin, fn () => $this->makeDeal('Own deal', $this->agent, $account, $stage));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)
            ->post('/marlin/deals/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertForbidden();
    }

    /**
     * Insert BRUT, nu factory — vezi docblock-ul testelor de prag ale Managerului. O
     * singură instrucțiune `INSERT` cu 1.001 rânduri, în loc de tot atâtea inserturi
     * individuale prin evenimentele Eloquent ale factory-ului.
     */
    private function bulkInsertAccounts(int $count, string $ownerId): void
    {
        TenantContext::run($this->marlin, function () use ($count, $ownerId): void {
            $now = now();
            $rows = [];

            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $this->marlin->getKey(),
                    'name' => 'Bulk Account '.$i,
                    'owner_user_id' => $ownerId,
                    'created_by' => $this->owner->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('accounts')->insert($rows);
        });

        $this->clearDatabaseTenantContext();
    }

    /** @return array{0: Account, 1: Stage} */
    private function dealFixture(): array
    {
        return TenantContext::run($this->marlin, function (): array {
            $pipeline = $this->makeDefaultPipeline($this->marlin);
            $stage = $pipeline['stages']['New'];

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            return [$account, $stage];
        });
    }

    private function makeDeal(string $title, User $owner, Account $account, Stage $stage): Deal
    {
        $deal = new Deal([
            'account_id' => $account->getKey(),
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $owner->getKey(),
            'title' => $title,
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $owner->getKey();
        $deal->save();

        return $deal;
    }

    private function soleOperation(string $resourceType): BulkOperation
    {
        return TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', $resourceType)->firstOrFail(),
        );
    }

    private function assertOperationStatus(BulkOperation $operation, string $status): void
    {
        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame($status, $fresh->status);
    }

    /**
     * Drenează coada `bulk` până se golește — planificatorul, chunk-urile ȘI jobul de
     * finalizare (`finally()` al batch-ului) ajung să ruleze, ca într-un worker real.
     */
    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
