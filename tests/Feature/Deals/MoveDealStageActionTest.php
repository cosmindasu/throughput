<?php

namespace Tests\Feature\Deals;

use App\Actions\Deals\MoveDealStageAction;
use App\Models\Account;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * `MoveDealStageAction` — §9.3 pas cu pas, BR-DEAL-01/02, FR-DEAL-03. Verificat direct pe
 * acțiune, fără HTTP: `DealStageController::move()` are propriul test (autorizare +
 * izolare de tenant), acesta acoperă doar regula de business.
 */
class MoveDealStageActionTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    /** @var array<string, Stage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $pipeline = $this->makeDefaultPipeline($this->tenant);
            $this->stages = $pipeline['stages'];

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_moving_to_a_different_stage_records_an_event_with_the_previous_stage(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);

            $moved = (new MoveDealStageAction)->execute($deal, $this->stages['Qualified'], $this->owner);

            $this->assertSame($this->stages['Qualified']->getKey(), $moved->stage_id);
            $this->assertSame(Deal::STATUS_OPEN, $moved->status);

            $event = DealStageEvent::query()->where('deal_id', $deal->getKey())->latest('changed_at')->first();
            $this->assertSame($this->stages['New']->getKey(), $event->from_stage_id);
            $this->assertSame($this->stages['Qualified']->getKey(), $event->to_stage_id);
        });
    }

    public function test_the_duration_is_computed_once_and_never_recalculated_retroactively(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);

            $firstEvent = DealStageEvent::query()->where('deal_id', $deal->getKey())->first();
            $this->assertNull($firstEvent->duration_in_previous_stage_seconds, 'BR-DEAL-02: primul eveniment nu are etapă anterioară.');

            $this->travel(2)->hours();
            (new MoveDealStageAction)->execute($deal->fresh(), $this->stages['Qualified'], $this->owner);

            $secondEvent = DealStageEvent::query()
                ->where('deal_id', $deal->getKey())
                ->where('to_stage_id', $this->stages['Qualified']->getKey())
                ->first();
            $this->assertEqualsWithDelta(2 * 3600, $secondEvent->duration_in_previous_stage_seconds, 5);

            $this->travel(3)->hours();
            (new MoveDealStageAction)->execute($deal->fresh(), $this->stages['Negotiation'], $this->owner);

            // BR-DEAL-02, filozofia append-only: valoarea calculată la inserare nu se
            // atinge niciodată retroactiv de o tranziție ulterioară.
            $firstEvent->refresh();
            $secondEvent->refresh();
            $this->assertNull($firstEvent->duration_in_previous_stage_seconds);
            $this->assertEqualsWithDelta(2 * 3600, $secondEvent->duration_in_previous_stage_seconds, 5);
        });
    }

    public function test_moving_to_the_same_stage_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);

            try {
                (new MoveDealStageAction)->execute($deal, $this->stages['New'], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('to_stage_id', $e->errors());
            }
        });
    }

    public function test_marking_won_without_a_value_is_refused_with_the_exact_message(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New'], value: null);

            try {
                (new MoveDealStageAction)->execute($deal, $this->stages['Won'], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertSame(['Set a deal value before marking as Won'], $e->errors()['to_stage_id']);
            }

            $this->assertSame(Deal::STATUS_OPEN, $deal->fresh()->status);
        });
    }

    public function test_marking_won_with_a_value_succeeds_and_derives_the_status(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New'], value: 15000.00);

            $moved = (new MoveDealStageAction)->execute($deal, $this->stages['Won'], $this->owner);

            $this->assertSame(Deal::STATUS_WON, $moved->status);
            $this->assertNull($moved->lost_reason);
        });
    }

    public function test_marking_lost_requires_a_reason_from_the_closed_list(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);

            try {
                (new MoveDealStageAction)->execute($deal, $this->stages['Lost'], $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lost_reason', $e->errors());
            }

            try {
                (new MoveDealStageAction)->execute($deal->fresh(), $this->stages['Lost'], $this->owner, 'not-a-real-reason');
                $this->fail('Expected a ValidationException for an out-of-list reason.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lost_reason', $e->errors());
            }

            $moved = (new MoveDealStageAction)->execute($deal->fresh(), $this->stages['Lost'], $this->owner, 'competition');
            $this->assertSame(Deal::STATUS_LOST, $moved->status);
            $this->assertSame('competition', $moved->lost_reason);
        });
    }

    public function test_reopening_a_lost_deal_clears_the_lost_reason(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);
            $lost = (new MoveDealStageAction)->execute($deal, $this->stages['Lost'], $this->owner, 'timing');

            $reopened = (new MoveDealStageAction)->execute($lost, $this->stages['Qualified'], $this->owner);

            $this->assertNull($reopened->lost_reason);
            $this->assertSame(Deal::STATUS_OPEN, $reopened->status);
        });
    }

    public function test_a_stage_from_another_pipeline_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $deal = $this->dealOn($this->stages['New']);

            $otherPipeline = Pipeline::query()->create(['name' => 'Retail', 'is_default' => false]);
            $foreignStage = Stage::query()->create([
                'pipeline_id' => $otherPipeline->getKey(),
                'name' => 'Contacted',
                'position' => 1,
            ]);

            try {
                (new MoveDealStageAction)->execute($deal, $foreignStage, $this->owner);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('to_stage_id', $e->errors());
            }
        });
    }

    private function dealOn(Stage $stage, ?float $value = 5000.00): Deal
    {
        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'title' => 'Annual supply agreement',
            'value' => $value,
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $this->owner->getKey();
        $deal->save();

        $event = new DealStageEvent([
            'deal_id' => $deal->getKey(),
            'from_stage_id' => null,
            'to_stage_id' => $stage->getKey(),
            'changed_at' => now(),
            'duration_in_previous_stage_seconds' => null,
        ]);
        $event->changed_by = $this->owner->getKey();
        $event->save();

        return $deal;
    }
}
