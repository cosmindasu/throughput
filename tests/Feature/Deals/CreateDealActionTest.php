<?php

namespace Tests\Feature\Deals;

use App\Actions\Deals\CreateDealAction;
use App\Models\Account;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * US-DEAL-01 — un deal nou pornește pe prima etapă a pipeline-ului implicit, cu owner =
 * creatorul, și inserează primul `deal_stage_events` (`from_stage_id = null`) în aceeași
 * tranzacție.
 */
class CreateDealActionTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $this->makeDefaultPipeline($this->tenant);

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_a_new_deal_lands_on_the_first_stage_owned_by_its_creator(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = (new CreateDealAction)->execute([
                'account_id' => $this->account->getKey(),
                'title' => 'Annual supply agreement',
                'value' => 12500.50,
            ], $this->owner);

            $this->assertSame('New', $deal->stage->name);
            $this->assertSame($this->owner->getKey(), $deal->owner_user_id);
            $this->assertSame($this->owner->getKey(), $deal->created_by);
            $this->assertSame(Deal::STATUS_OPEN, $deal->status);
        });
    }

    public function test_the_first_stage_event_has_no_previous_stage_and_no_duration(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = (new CreateDealAction)->execute([
                'account_id' => $this->account->getKey(),
                'title' => 'Annual supply agreement',
                'value' => null,
            ], $this->owner);

            $events = DealStageEvent::query()->where('deal_id', $deal->getKey())->get();

            $this->assertCount(1, $events);
            $this->assertNull($events->first()->from_stage_id);
            $this->assertSame($deal->stage_id, $events->first()->to_stage_id);
            $this->assertNull($events->first()->duration_in_previous_stage_seconds);
            $this->assertSame($this->owner->getKey(), $events->first()->changed_by);
        });
    }

    public function test_an_explicit_owner_is_honoured_when_provided(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->tenant, function () use ($agent): void {
            $deal = (new CreateDealAction)->execute([
                'account_id' => $this->account->getKey(),
                'title' => 'Annual supply agreement',
                'owner_user_id' => $agent->getKey(),
            ], $this->owner);

            $this->assertSame($agent->getKey(), $deal->owner_user_id);
            $this->assertSame($this->owner->getKey(), $deal->created_by);
        });
    }
}
