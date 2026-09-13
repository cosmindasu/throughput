<?php

namespace Tests\Feature\Pipeline;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pachetul D — configurarea pipeline-ului și a etapelor (FR-DEAL-02, BR-DEAL-01, §7.4, §9.2).
 *
 * Prin cerere HTTP reală, ca `DashboardTest`: `auth → SetSessionContext → ResolveWorkspace`,
 * global scope, RLS, roluri per tenant, props Inertia — nu doar policy-ul izolat.
 */
class PipelineConfigurationTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    private Pipeline $pipeline;

    /** @var array<string, Stage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->owner = $this->makeMember($this->marlin, 'pipeline.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'pipeline.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'pipeline.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'pipeline.viewer@throughput.dev', Permissions::VIEWER);

        $seeded = $this->seedPipeline($this->marlin);
        $this->pipeline = $seeded['pipeline'];
        $this->stages = $seeded['stages'];

        $this->clearDatabaseTenantContext();
    }

    public function test_viewer_sees_the_stages_read_only_without_manage_actions(): void
    {
        $response = $this->actingAs($this->viewer)->get('/marlin/pipeline');

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pipeline/Index')
            ->has('stages', 4)
            ->where('can.manage', false)
            ->where('stages.0.name', 'New')
            ->where('stages.0.canDelete', false)
            ->where('stages.2.isWon', true)
            ->where('stages.3.isLost', true)
        );
    }

    public function test_agent_cannot_view_the_pipeline_configuration_screen(): void
    {
        // Matricea §7.4 dă Agentului „—" pe configurarea de pipeline/etape — asimetrie
        // semnalată în `Permissions::forRoles()`, nu corectată în tăcere aici.
        $this->actingAs($this->agent)->get('/marlin/pipeline')->assertForbidden();
    }

    public function test_manager_and_owner_see_manage_actions_available(): void
    {
        foreach ([$this->manager, $this->owner] as $user) {
            $this->actingAs($user)->get('/marlin/pipeline')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Pipeline/Index')
                    ->where('can.manage', true)
                    ->where('stages.0.canDelete', true)
                );
        }
    }

    public function test_manager_can_create_a_stage_appended_at_the_end_of_the_order(): void
    {
        $this->actingAs($this->manager)->post('/marlin/pipeline/stages', [
            'name' => 'Negotiation',
            'probability' => 60,
        ])->assertRedirect();

        $this->actingAs($this->manager)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('stages', 5)
                ->where('stages.4.name', 'Negotiation')
                ->where('stages.4.position', 5)
                ->where('stages.4.probability', 60)
                ->where('stages.4.isWon', false)
                ->where('stages.4.isLost', false)
            );
    }

    public function test_owner_can_edit_a_stage(): void
    {
        $stage = $this->stages['New'];

        $this->actingAs($this->owner)->patch("/marlin/pipeline/stages/{$stage->id}", [
            'name' => 'Inbound Lead',
            'probability' => 15,
        ])->assertRedirect();

        $this->actingAs($this->owner)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.0.name', 'Inbound Lead')
                ->where('stages.0.probability', 15)
            );
    }

    public function test_viewer_is_forbidden_from_every_write_action(): void
    {
        $stage = $this->stages['New'];

        $this->actingAs($this->viewer)->post('/marlin/pipeline/stages', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->patch("/marlin/pipeline/stages/{$stage->id}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->viewer)->delete("/marlin/pipeline/stages/{$stage->id}")->assertForbidden();
        $this->actingAs($this->viewer)->put('/marlin/pipeline/stages/order', [
            'stage_ids' => array_map(fn (Stage $s) => $s->id, array_values($this->stages)),
        ])->assertForbidden();
    }

    public function test_stage_name_must_be_unique_within_the_pipeline(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/pipeline/stages', [
            'name' => 'New', // există deja
        ]);

        $response->assertSessionHasErrors('name');

        $this->actingAs($this->owner)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stages', 4));
    }

    public function test_at_most_one_won_stage_is_allowed_per_pipeline(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/pipeline/stages', [
            'name' => 'Second Won',
            'is_won' => true,
        ]);

        $response->assertSessionHasErrors('is_won');
    }

    public function test_at_most_one_lost_stage_is_allowed_per_pipeline(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/pipeline/stages', [
            'name' => 'Second Lost',
            'is_lost' => true,
        ]);

        $response->assertSessionHasErrors('is_lost');
    }

    public function test_a_stage_cannot_be_marked_both_won_and_lost(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/pipeline/stages', [
            'name' => 'Contradiction',
            'is_won' => true,
            'is_lost' => true,
        ]);

        $response->assertSessionHasErrors('is_lost');
    }

    public function test_editing_a_stage_may_keep_its_own_won_flag(): void
    {
        // Regula „cel mult o etapă Won" nu trebuie să se lovească de EA ÎNSĂȘI la editare.
        $won = $this->stages['Won'];

        $this->actingAs($this->owner)->patch("/marlin/pipeline/stages/{$won->id}", [
            'name' => 'Won',
            'probability' => 100,
            'is_won' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_stage_marked_won_with_deals_cannot_be_deleted(): void
    {
        $won = $this->stages['Won'];
        $this->attachDeal($this->marlin, $won, $this->owner);

        $response = $this->actingAs($this->owner)->delete("/marlin/pipeline/stages/{$won->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertStageStillExists($won->id);
    }

    public function test_a_stage_without_marks_but_with_deals_cannot_be_deleted(): void
    {
        // BR-DEAL-01, al doilea caz: nicio marcă Won/Lost, dar tot blocată — `deals.stage_id`
        // e o FK fără cascadă, ștergerea ar rupe integritatea, nu doar „regula de business".
        $new = $this->stages['New'];
        $this->attachDeal($this->marlin, $new, $this->owner);

        $response = $this->actingAs($this->owner)->delete("/marlin/pipeline/stages/{$new->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertStageStillExists($new->id);
    }

    public function test_a_stage_without_deals_can_be_deleted(): void
    {
        $qualified = $this->stages['Qualified'];

        $this->actingAs($this->owner)->delete("/marlin/pipeline/stages/{$qualified->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->owner)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('stages', 3));
    }

    public function test_reordering_persists_the_new_positions(): void
    {
        $ordered = [
            $this->stages['Lost']->id,
            $this->stages['Won']->id,
            $this->stages['Qualified']->id,
            $this->stages['New']->id,
        ];

        $this->actingAs($this->manager)->put('/marlin/pipeline/stages/order', [
            'stage_ids' => $ordered,
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->manager)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.0.name', 'Lost')
                ->where('stages.0.position', 1)
                ->where('stages.1.name', 'Won')
                ->where('stages.1.position', 2)
                ->where('stages.2.name', 'Qualified')
                ->where('stages.2.position', 3)
                ->where('stages.3.name', 'New')
                ->where('stages.3.position', 4)
            );
    }

    public function test_reordering_rejects_duplicate_ids_and_writes_nothing(): void
    {
        $new = $this->stages['New'];
        $qualified = $this->stages['Qualified'];

        $response = $this->actingAs($this->manager)->put('/marlin/pipeline/stages/order', [
            // Duplicat + „Won"/„Lost" complet lipsă din listă.
            'stage_ids' => [$new->id, $new->id, $qualified->id],
        ]);

        $response->assertSessionHasErrors('stage_ids');
        $this->assertOrderUnchanged();
    }

    public function test_reordering_rejects_a_stage_id_missing_from_the_pipeline_and_writes_nothing(): void
    {
        $response = $this->actingAs($this->manager)->put('/marlin/pipeline/stages/order', [
            'stage_ids' => [$this->stages['New']->id, $this->stages['Qualified']->id, $this->stages['Won']->id],
            // „Lost" lipsește din listă.
        ]);

        $response->assertSessionHasErrors('stage_ids');
        $this->assertOrderUnchanged();
    }

    public function test_reordering_rejects_a_stage_id_from_another_tenant_and_writes_nothing(): void
    {
        // Global scope + RLS fac etapa celuilalt tenant invizibilă pentru
        // `$pipeline->stages()`, deci id-ul străin cade în categoria „nu aparține acestui
        // pipeline" — exact ca un id inventat.
        $foreignStage = TenantContext::run($this->cascade, function (): Stage {
            $pipeline = Pipeline::query()->create(['name' => 'Cascade Standard', 'is_default' => true]);

            return Stage::query()->create(['pipeline_id' => $pipeline->id, 'name' => 'Foreign', 'position' => 1]);
        });

        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->manager)->put('/marlin/pipeline/stages/order', [
            'stage_ids' => [
                $foreignStage->id,
                $this->stages['New']->id,
                $this->stages['Qualified']->id,
                $this->stages['Won']->id,
                $this->stages['Lost']->id,
            ],
        ]);

        $response->assertSessionHasErrors('stage_ids');
        $this->assertOrderUnchanged();
    }

    /**
     * @return array{pipeline: Pipeline, stages: array<string, Stage>}
     */
    private function seedPipeline(Tenant $tenant): array
    {
        return TenantContext::run($tenant, function () {
            $pipeline = Pipeline::query()->create(['name' => 'Standard', 'is_default' => true]);

            $stages = [];
            $stages['New'] = Stage::query()->create([
                'pipeline_id' => $pipeline->id, 'name' => 'New', 'position' => 1, 'probability' => 10,
            ]);
            $stages['Qualified'] = Stage::query()->create([
                'pipeline_id' => $pipeline->id, 'name' => 'Qualified', 'position' => 2, 'probability' => 40,
            ]);
            $stages['Won'] = Stage::query()->create([
                'pipeline_id' => $pipeline->id, 'name' => 'Won', 'position' => 3, 'is_won' => true, 'probability' => 100,
            ]);
            $stages['Lost'] = Stage::query()->create([
                'pipeline_id' => $pipeline->id, 'name' => 'Lost', 'position' => 4, 'is_lost' => true, 'probability' => 0,
            ]);

            return ['pipeline' => $pipeline, 'stages' => $stages];
        });
    }

    private function attachDeal(Tenant $tenant, Stage $stage, User $owner): Deal
    {
        return TenantContext::run($tenant, function () use ($stage, $owner): Deal {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $owner->getKey(),
                'title' => 'Annual fastener supply agreement',
                'value' => 12_500,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $owner->getKey();
            $deal->save();

            return $deal;
        });
    }

    private function assertStageStillExists(string $stageId): void
    {
        $stillExists = TenantContext::run($this->marlin, fn () => Stage::query()->whereKey($stageId)->exists());

        $this->assertTrue($stillExists, 'The stage should not have been deleted (BR-DEAL-01).');
    }

    private function assertOrderUnchanged(): void
    {
        $this->actingAs($this->owner)->get('/marlin/pipeline')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stages.0.name', 'New')
                ->where('stages.0.position', 1)
                ->where('stages.1.name', 'Qualified')
                ->where('stages.1.position', 2)
                ->where('stages.2.name', 'Won')
                ->where('stages.2.position', 3)
                ->where('stages.3.name', 'Lost')
                ->where('stages.3.position', 4)
            );
    }
}
