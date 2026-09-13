<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Accounts/Show — FR-CRM-04. Contract, tab-ul „Activity" deferred, izolare de tenant pe
 * `show()`, motivul de blocare al ștergerii (BR-CRM-01).
 */
class AccountShowTest extends TestCase
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

    public function test_the_page_has_the_expected_contract_and_activity_is_deferred(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/accounts/{$account->id}");

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Show')
            ->where('account.id', $account->id)
            ->has('contacts')
            ->has('deals')
            ->where('deletionBlockedReason', null)
            ->where('can.edit', true)
            ->where('can.delete', true)
            ->missing('activity')
        );

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('activity'))
        );
    }

    public function test_deal_and_order_activity_appear_in_the_timeline(): void
    {
        $account = TenantContext::run($this->marlin, function (): Account {
            $account = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Annual fastener supply agreement',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_CONFIRMED,
                'grand_total' => 1250.50,
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            return $account;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$account->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $deferred) => $deferred->has('activity', 2))
            );
    }

    public function test_an_account_from_another_tenant_is_not_found(): void
    {
        $strangerOwner = $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);
        $foreignAccount = TenantContext::run($this->cascade, fn () => (new AccountFactory)->create(['created_by' => $strangerOwner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$foreignAccount->id}")->assertNotFound();
    }

    public function test_deletion_is_blocked_with_an_explicit_reason_when_deals_or_orders_exist(): void
    {
        $account = TenantContext::run($this->marlin, function (): Account {
            $account = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);

            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => 'Blocking deal',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            return $account;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$account->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deletionBlockedReason', 'This account cannot be deleted: it has 1 deal.')
            );

        $this->actingAs($this->owner)->delete("/marlin/accounts/{$account->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull($account->fresh());
    }

    public function test_deletion_succeeds_when_nothing_blocks_it(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/accounts/{$account->id}")
            ->assertRedirect('/marlin/accounts')
            ->assertSessionHas('success');
    }
}
