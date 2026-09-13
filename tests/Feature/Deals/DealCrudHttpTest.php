<?php

namespace Tests\Feature\Deals;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * CRUD de deals prin HTTP — US-DEAL-01 (creare din pagina unui cont), contractul de
 * props (plan §1.2 regula 5) și RBAC pe fiecare acțiune de scriere.
 */
class DealCrudHttpTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($owner): void {
            $this->makeDefaultPipeline($this->marlin);

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_create_is_prefilled_from_the_account_query_parameter(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner2@throughput.dev', Permissions::OWNER);

        $this->actingAs($owner)
            ->get("/marlin/deals/create?account={$this->account->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Create')
                ->where('account.id', $this->account->getKey())
                ->where('can.changeOwner', true)
            );
    }

    public function test_an_agent_cannot_choose_a_different_owner_on_create(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)
            ->get("/marlin/deals/create?account={$this->account->getKey()}")
            ->assertInertia(fn (Assert $page) => $page->where('can.changeOwner', false));
    }

    public function test_storing_a_deal_creates_it_with_the_first_stage_event(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        $response = $this->actingAs($agent)->post('/marlin/deals', [
            'account_id' => $this->account->getKey(),
            'title' => 'Annual supply agreement',
            'value' => 12500.50,
        ]);

        $response->assertRedirect();

        TenantContext::run($this->marlin, function () use ($agent): void {
            $deal = Deal::query()->where('title', 'Annual supply agreement')->firstOrFail();

            $this->assertSame($agent->getKey(), $deal->owner_user_id);
            $this->assertSame('New', $deal->stage->name);

            $events = DealStageEvent::query()->where('deal_id', $deal->getKey())->get();
            $this->assertCount(1, $events);
            $this->assertNull($events->first()->from_stage_id);
        });
    }

    public function test_an_agent_cannot_assign_a_different_owner_even_by_forging_the_field(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);
        $otherAgent = $this->makeMember($this->marlin, 'other-agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->post('/marlin/deals', [
            'account_id' => $this->account->getKey(),
            'title' => 'Annual supply agreement',
            'owner_user_id' => $otherAgent->getKey(),
        ])->assertRedirect();

        TenantContext::run($this->marlin, function () use ($agent): void {
            $deal = Deal::query()->where('title', 'Annual supply agreement')->firstOrFail();
            $this->assertSame($agent->getKey(), $deal->owner_user_id);
        });
    }

    public function test_a_viewer_cannot_create_a_deal(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)
            ->get("/marlin/deals/create?account={$this->account->getKey()}")
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/marlin/deals', ['account_id' => $this->account->getKey(), 'title' => 'x'])
            ->assertForbidden();
    }

    public function test_show_exposes_the_contract_and_records_it_as_recently_viewed(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner3@throughput.dev', Permissions::OWNER);
        $deal = $this->createDeal($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->get("/marlin/deals/{$deal->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Show')
                ->has('deal.id')
                ->has('deal.title')
                ->has('stageEvents', 1)
                ->has('stages')
                ->where('can.edit', true)
                ->where('can.delete', true)
                ->where('can.moveStage', true)
                ->where('can.changeOwner', true)
            );
    }

    public function test_an_agent_sees_a_foreign_deal_but_without_edit_or_move_rights(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner4@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent2@throughput.dev', Permissions::AGENT);
        $deal = $this->createDeal($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->get("/marlin/deals/{$deal->getKey()}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', false)
                ->where('can.moveStage', false)
                ->where('can.changeOwner', false)
            );
    }

    public function test_updating_a_deal_does_not_change_its_stage(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner5@throughput.dev', Permissions::OWNER);
        $deal = $this->createDeal($owner);
        $originalStageId = $deal->stage_id;
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->put("/marlin/deals/{$deal->getKey()}", [
                'account_id' => $deal->account_id,
                'title' => 'Renewed supply agreement',
                'value' => 20000,
            ])
            ->assertRedirect();

        TenantContext::run($this->marlin, function () use ($deal, $originalStageId): void {
            $fresh = $deal->fresh();
            $this->assertSame('Renewed supply agreement', $fresh->title);
            $this->assertSame($originalStageId, $fresh->stage_id);
        });
    }

    public function test_an_agent_cannot_update_a_deal_they_do_not_own(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner6@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->marlin, 'agent3@throughput.dev', Permissions::AGENT);
        $deal = $this->createDeal($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)
            ->put("/marlin/deals/{$deal->getKey()}", ['account_id' => $deal->account_id, 'title' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_deleting_a_deal_is_restricted_to_deals_delete(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner7@throughput.dev', Permissions::OWNER);
        $viewer = $this->makeMember($this->marlin, 'viewer2@throughput.dev', Permissions::VIEWER);
        $deal = $this->createDeal($owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)
            ->delete("/marlin/deals/{$deal->getKey()}")
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete("/marlin/deals/{$deal->getKey()}")
            ->assertRedirect();

        TenantContext::run($this->marlin, function () use ($deal): void {
            $this->assertNull(Deal::query()->find($deal->getKey()));
        });
    }

    /**
     * §9 task — câmpul „Account" cu `AccountCombobox`: fără `?account=` pagina se
     * deschide cu câmpul gol, nu 404.
     */
    public function test_create_without_an_account_query_parameter_shows_an_empty_field(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner8@throughput.dev', Permissions::OWNER);

        $this->actingAs($owner)
            ->get('/marlin/deals/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Create')
                ->where('account', null)
                ->where('contacts', [])
            );
    }

    public function test_storing_a_deal_without_an_account_is_rejected(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner9@throughput.dev', Permissions::OWNER);

        $response = $this->actingAs($owner)
            ->from('/marlin/deals/create')
            ->post('/marlin/deals', ['title' => 'No account deal']);

        $response->assertRedirect('/marlin/deals/create');
        $response->assertSessionHasErrors('account_id');

        TenantContext::run($this->marlin, function (): void {
            $this->assertDatabaseMissing('deals', ['title' => 'No account deal']);
        });
    }

    public function test_storing_a_deal_with_an_account_from_another_tenant_is_rejected(): void
    {
        $cascade = $this->makeTenant('cascade-store', 'Cascade Hydraulic Components');
        $foreignAccountId = TenantContext::run($cascade, function () use ($cascade) {
            $cascadeOwner = $this->makeMember($cascade, 'cascade.owner1@throughput.dev', Permissions::OWNER);
            $account = new Account(['name' => 'Cascade Bearing Co.']);
            $account->created_by = $cascadeOwner->getKey();
            $account->save();

            return $account->id;
        });

        $owner = $this->makeMember($this->marlin, 'owner10@throughput.dev', Permissions::OWNER);

        $response = $this->actingAs($owner)
            ->from('/marlin/deals/create')
            ->post('/marlin/deals', ['account_id' => $foreignAccountId, 'title' => 'Cross tenant deal']);

        $response->assertRedirect('/marlin/deals/create');
        $response->assertSessionHasErrors('account_id');

        TenantContext::run($this->marlin, function (): void {
            $this->assertDatabaseMissing('deals', ['title' => 'Cross tenant deal']);
        });
    }

    public function test_updating_a_deal_with_an_account_from_another_tenant_is_rejected(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner11@throughput.dev', Permissions::OWNER);
        $deal = $this->createDeal($owner);
        $originalAccountId = $deal->account_id;
        $this->clearDatabaseTenantContext();

        $cascade = $this->makeTenant('cascade-update', 'Cascade Hydraulic Components');
        $foreignAccountId = TenantContext::run($cascade, function () use ($cascade) {
            $cascadeOwner = $this->makeMember($cascade, 'cascade.owner2@throughput.dev', Permissions::OWNER);
            $account = new Account(['name' => 'Cascade Bearing Co.']);
            $account->created_by = $cascadeOwner->getKey();
            $account->save();

            return $account->id;
        });

        $response = $this->actingAs($owner)
            ->from("/marlin/deals/{$deal->getKey()}/edit")
            ->put("/marlin/deals/{$deal->getKey()}", [
                'account_id' => $foreignAccountId,
                'title' => $deal->title,
            ]);

        $response->assertRedirect("/marlin/deals/{$deal->getKey()}/edit");
        $response->assertSessionHasErrors('account_id');

        TenantContext::run($this->marlin, function () use ($deal, $originalAccountId): void {
            $this->assertSame($originalAccountId, $deal->fresh()->account_id);
        });
    }

    public function test_storing_a_deal_with_a_contact_from_a_different_account_is_rejected(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner12@throughput.dev', Permissions::OWNER);

        $foreignContactId = TenantContext::run($this->marlin, function () use ($owner): string {
            $otherAccount = new Account(['name' => 'Other Account LLC']);
            $otherAccount->created_by = $owner->getKey();
            $otherAccount->save();

            $contact = new Contact([
                'account_id' => $otherAccount->getKey(),
                'first_name' => 'Jamie',
                'last_name' => 'Smith',
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            return $contact->getKey();
        });

        $response = $this->actingAs($owner)
            ->from('/marlin/deals/create')
            ->post('/marlin/deals', [
                'account_id' => $this->account->getKey(),
                'primary_contact_id' => $foreignContactId,
                'title' => 'Deal with wrong contact',
            ]);

        $response->assertRedirect('/marlin/deals/create');
        $response->assertSessionHasErrors('primary_contact_id');

        TenantContext::run($this->marlin, function (): void {
            $this->assertDatabaseMissing('deals', ['title' => 'Deal with wrong contact']);
        });
    }

    public function test_updating_a_deal_with_a_contact_from_a_different_account_is_rejected(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner13@throughput.dev', Permissions::OWNER);
        $deal = $this->createDeal($owner);

        $foreignContactId = TenantContext::run($this->marlin, function () use ($owner): string {
            $otherAccount = new Account(['name' => 'Other Account LLC 2']);
            $otherAccount->created_by = $owner->getKey();
            $otherAccount->save();

            $contact = new Contact([
                'account_id' => $otherAccount->getKey(),
                'first_name' => 'Jamie',
                'last_name' => 'Smith',
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            return $contact->getKey();
        });

        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)
            ->from("/marlin/deals/{$deal->getKey()}/edit")
            ->put("/marlin/deals/{$deal->getKey()}", [
                'account_id' => $deal->account_id,
                'primary_contact_id' => $foreignContactId,
                'title' => $deal->title,
            ]);

        $response->assertRedirect("/marlin/deals/{$deal->getKey()}/edit");
        $response->assertSessionHasErrors('primary_contact_id');
    }

    /**
     * §9 task — mutarea pe alt cont, cu un contact valid al noului cont, reușește;
     * etapa și owner-ul rămân neschimbate (nicio inserare nouă în `deal_stage_events`).
     */
    public function test_updating_a_deal_moves_it_to_another_account_with_a_valid_contact(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner14@throughput.dev', Permissions::OWNER);
        $deal = $this->createDeal($owner);
        $originalStageId = $deal->stage_id;
        $originalOwnerId = $deal->owner_user_id;

        [$newAccountId, $newContactId] = TenantContext::run($this->marlin, function () use ($owner): array {
            $newAccount = new Account(['name' => 'New Account Destination LLC']);
            $newAccount->created_by = $owner->getKey();
            $newAccount->save();

            $contact = new Contact([
                'account_id' => $newAccount->getKey(),
                'first_name' => 'Robin',
                'last_name' => 'Lee',
            ]);
            $contact->created_by = $owner->getKey();
            $contact->save();

            return [$newAccount->getKey(), $contact->getKey()];
        });

        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->put("/marlin/deals/{$deal->getKey()}", [
                'account_id' => $newAccountId,
                'primary_contact_id' => $newContactId,
                'title' => $deal->title,
            ])
            ->assertRedirect("/marlin/deals/{$deal->getKey()}");

        TenantContext::run(
            $this->marlin,
            function () use ($deal, $newAccountId, $newContactId, $originalStageId, $originalOwnerId): void {
                $fresh = $deal->fresh();
                $this->assertSame($newAccountId, $fresh->account_id);
                $this->assertSame($newContactId, $fresh->primary_contact_id);
                $this->assertSame($originalStageId, $fresh->stage_id);
                $this->assertSame($originalOwnerId, $fresh->owner_user_id);

                // Mutarea de cont NU e o tranziție de etapă (§9 task) — rămâne un
                // singur eveniment, cel de creare.
                $events = DealStageEvent::query()->where('deal_id', $deal->getKey())->get();
                $this->assertCount(1, $events);
            }
        );
    }

    private function createDeal(User $owner): Deal
    {
        return TenantContext::run($this->marlin, function () use ($owner): Deal {
            $stage = Stage::query()->where('name', 'New')->firstOrFail();

            $deal = new Deal([
                'account_id' => $this->account->getKey(),
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Annual supply agreement',
                'value' => 5000,
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
