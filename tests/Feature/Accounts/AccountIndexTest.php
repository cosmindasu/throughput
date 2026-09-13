<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Accounts/Index — FR-CRM-03, US-CRM-02. Prin lanțul real de middleware (auth →
 * session.context → workspace), nu direct pe listă/policy.
 */
class AccountIndexTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_the_page_has_the_expected_contract_and_the_rows_are_deferred(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(3)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->owner->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Index')
            ->has('list')
            ->has('owners')
            ->where('can.create', true)
            ->where('can.export', true)
            // Deferred: absent din răspunsul inițial (FR-PERF-01).
            ->missing('accounts')
        );

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Index')
            ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                ->has('accounts.data', 3)
                ->has('accounts.data.0.id')
                ->has('accounts.data.0.name')
                ->has('accounts.data.0.status')
                ->has('accounts.data.0.canEdit')
                ->where('accounts.nextCursor', null)
            )
        );
    }

    public function test_an_agent_defaults_to_my_accounts_and_can_switch_to_all(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, function () use ($agent, $manager): void {
            (new AccountFactory)->count(2)->create(['created_by' => $agent->getKey(), 'owner_user_id' => $agent->getKey()]);
            (new AccountFactory)->count(3)->create(['created_by' => $manager->getKey(), 'owner_user_id' => $manager->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get('/marlin/accounts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('list.filter.owner', 'me')
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('accounts.data', 2))
            );

        $this->actingAs($agent)->get('/marlin/accounts?filter[owner]=all')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('accounts.data', 5))
            );
    }

    public function test_filtering_by_status(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(2)->create([
                'created_by' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);
            (new AccountFactory)->count(4)->create([
                'created_by' => $this->owner->getKey(),
                'status' => Account::STATUS_INACTIVE,
            ]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts?filter[status]=active')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('accounts.data', 2))
            );
    }

    public function test_searching_by_name(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->create(['name' => 'Northwind Industrial Supply LLC', 'created_by' => $this->owner->getKey()]);
            (new AccountFactory)->create(['name' => 'Cascade Hydraulics Group Inc.', 'created_by' => $this->owner->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts?filter[q]=northwind')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.name', 'Northwind Industrial Supply LLC')
                )
            );
    }

    /**
     * ADR-011 / BR-TEN-03 — dezactivarea unui membru nu reatribuie nimic: contul unui
     * coleg plecat trebuie găsit la „Unassigned", nu pierdut.
     */
    public function test_unassigned_includes_accounts_owned_by_a_deactivated_member(): void
    {
        $leaver = $this->makeMember($this->marlin, 'left@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($leaver): void {
            (new AccountFactory)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => null]);
            (new AccountFactory)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $leaver->getKey()]);
            (new AccountFactory)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->owner->getKey()]);

            Membership::query()->where('user_id', $leaver->getKey())->update(['status' => Membership::STATUS_DEACTIVATED]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts?filter[owner]=unassigned')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('accounts.data', 2))
            );
    }

    public function test_sorting_and_a_second_page_through_the_cursor(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(60)->create(['created_by' => $this->owner->getKey()]);
        });
        $this->clearDatabaseTenantContext();

        $firstPageCursor = null;

        $this->actingAs($this->owner)->get('/marlin/accounts?sort=name')
            ->assertInertia(function (AssertableInertia $page) use (&$firstPageCursor): void {
                $page->loadDeferredProps(function (AssertableInertia $deferred) use (&$firstPageCursor): void {
                    $deferred->has('accounts.data', 50);
                    $deferred->where('accounts.nextCursor', function ($cursor) use (&$firstPageCursor) {
                        $firstPageCursor = $cursor;

                        return $cursor !== null;
                    });
                });
            });

        $this->assertNotNull($firstPageCursor, 'Prima pagină ar fi trebuit să aibă un cursor următor pe 60 de rânduri.');

        $this->actingAs($this->owner)->get('/marlin/accounts?sort=name&cursor='.$firstPageCursor)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->has('accounts.data', 10)
                    ->where('accounts.nextCursor', null)
                )
            );
    }

    public function test_a_viewer_has_no_create_button_but_keeps_export(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get('/marlin/accounts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.create', false)
                ->where('can.export', true)
            );

        // Server-side: nu doar butonul lipsă, refuzul e real.
        $this->actingAs($viewer)->post('/marlin/accounts', ['name' => 'Should not be created'])
            ->assertForbidden();
    }

    public function test_accounts_are_isolated_per_tenant_over_http(): void
    {
        $strangerOwner = $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['name' => 'Marlin Only Account', 'created_by' => $this->owner->getKey()]));
        TenantContext::run($this->cascade, fn () => (new AccountFactory)->create(['name' => 'Cascade Only Account', 'created_by' => $strangerOwner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.name', 'Marlin Only Account')
                )
            );

        $this->actingAs($this->owner)->get('/cascade/accounts')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred
                    ->has('accounts.data', 1)
                    ->where('accounts.data.0.name', 'Cascade Only Account')
                )
            );
    }
}
