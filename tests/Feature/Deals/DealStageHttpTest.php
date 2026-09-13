<?php

namespace Tests\Feature\Deals;

use App\Models\Account;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * `PATCH /deals/{deal}/stage` prin lanțul real de middleware (auth → session.context →
 * workspace) — DoD: „teste HTTP prin lanțul real de middleware; `can` verificat pentru
 * cele 4 roluri; izolare de tenant pe fiecare cale nouă".
 */
class DealStageHttpTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private Tenant $cascade;

    /** @var array<string, Stage> */
    private array $stages;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $pipeline = $this->makeDefaultPipeline($this->marlin);
            $this->stages = $pipeline['stages'];

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_viewer_cannot_move_a_deal(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $deal = $this->dealOn('New', $viewer);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Qualified']->getKey()])
            ->assertForbidden();
    }

    public function test_an_agent_cannot_move_a_deal_they_do_not_own(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $deal = $this->dealOn('New', $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Qualified']->getKey()])
            ->assertForbidden();
    }

    public function test_an_agent_can_move_their_own_deal(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $deal = $this->dealOn('New', $agent);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Qualified']->getKey()])
            ->assertRedirect();

        TenantContext::run($this->marlin, function () use ($deal): void {
            $this->assertSame($this->stages['Qualified']->getKey(), $deal->fresh()->stage_id);
        });
    }

    public function test_a_manager_moving_a_deal_to_won_without_a_value_is_rejected_with_the_exact_message(): void
    {
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $deal = $this->dealOn('New', $manager, value: null);
        $this->clearDatabaseTenantContext();

        $this->actingAs($manager)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Won']->getKey()])
            ->assertSessionHasErrors(['to_stage_id' => 'Set a deal value before marking as Won']);

        TenantContext::run($this->marlin, function () use ($deal): void {
            $this->assertSame(Deal::STATUS_OPEN, $deal->fresh()->status);
        });
    }

    public function test_a_deal_from_another_tenant_is_not_found(): void
    {
        $stranger = $this->makeMember($this->cascade, 'stranger@throughput.dev', Permissions::OWNER);
        $marlinOwner = $this->makeMember($this->marlin, 'owner3@throughput.dev', Permissions::OWNER);
        $deal = $this->dealOn('New', $marlinOwner);
        $this->clearDatabaseTenantContext();

        // Ruta e sub `/cascade/...`, deci `{deal}` (un ULID din `marlin`) nu există în
        // scope-ul tenantului `cascade` — 404, nu 403 (BOLA, §20.2/§18.5).
        $this->actingAs($stranger)
            ->patch("/cascade/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Qualified']->getKey()])
            ->assertNotFound();
    }

    public function test_a_stage_from_another_tenant_is_not_found(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner4@throughput.dev', Permissions::OWNER);
        $deal = $this->dealOn('New', $owner);

        $foreignStageId = TenantContext::run($this->cascade, function () {
            $pipeline = Pipeline::query()->create(['name' => 'Retail', 'is_default' => true]);

            return Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Contacted', 'position' => 1])->getKey();
        });

        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $foreignStageId])
            ->assertNotFound();
    }

    public function test_moving_to_a_lost_stage_without_a_reason_is_rejected(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner5@throughput.dev', Permissions::OWNER);
        $deal = $this->dealOn('New', $owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", ['to_stage_id' => $this->stages['Lost']->getKey()])
            ->assertSessionHasErrors('lost_reason');

        $this->actingAs($owner)
            ->patch("/marlin/deals/{$deal->getKey()}/stage", [
                'to_stage_id' => $this->stages['Lost']->getKey(),
                'lost_reason' => 'price',
            ])
            ->assertRedirect();

        TenantContext::run($this->marlin, function () use ($deal): void {
            $fresh = $deal->fresh();
            $this->assertSame(Deal::STATUS_LOST, $fresh->status);
            $this->assertSame('price', $fresh->lost_reason);
        });
    }

    private function dealOn(string $stageName, User $owner, ?float $value = 5000.00): Deal
    {
        return TenantContext::run($this->marlin, function () use ($stageName, $owner, $value): Deal {
            $stage = $this->stages[$stageName];

            $deal = new Deal([
                'account_id' => $this->account->getKey(),
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Annual supply agreement',
                'value' => $value,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();

            $event = new DealStageEvent([
                'deal_id' => $deal->getKey(),
                'from_stage_id' => null,
                'to_stage_id' => $stage->getKey(),
                'changed_at' => now(),
                'duration_in_previous_stage_seconds' => null,
            ]);
            $event->changed_by = $owner->getKey();
            $event->save();

            return $deal;
        });
    }
}
