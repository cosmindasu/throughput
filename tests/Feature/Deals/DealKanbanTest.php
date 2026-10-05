<?php

namespace Tests\Feature\Deals;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * `Deals/Kanban` — contract + `can` per card pentru cele 4 roluri (§7.3, §9.3). Un
 * Viewer sau un Agent pe deal-ul altcuiva NU trebuie să primească `can.moveStage: true`
 * — front-end-ul decide `draggable` și prezența meniului „Move to stage…" doar din
 * acest prop, niciodată din rolul brut.
 */
class DealKanbanTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $tenant;

    private Account $account;

    private User $owner;

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

    public function test_columns_follow_stage_position_and_cap_cards_at_fifty(): void
    {
        TenantContext::run($this->tenant, function (): void {
            for ($i = 0; $i < 52; $i++) {
                $this->dealOn('New', $this->owner, sprintf('Deal %02d', $i));
            }
        });

        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/deals/board?owner=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Kanban')
                ->has('columns', 6)
                ->where('columns.0.stage.name', 'New')
                ->where('columns.1.stage.name', 'Qualified')
                ->where('columns.4.stage.name', 'Won')
                ->where('columns.5.stage.name', 'Lost')
                ->where('columns.0.total', 52)
                ->has('columns.0.deals', 50)
                ->where('columns.0.hasMore', true)
                ->where('columns.1.hasMore', false)
            );
    }

    /**
     * P3-d, code review: varianta veche făcea `count()` + `latest()->limit(50)` +
     * eager-load-uri PE COLOANĂ — ~25-40 interogări măsurate la 6-8 etape. Măsurat pe
     * varianta nouă, cu 6 etape: 8 interogări de autentificare/RBAC/sesiune (comune
     * oricărei cereri autentificate, nu doar board-ului) + 7 din `board()` însuși
     * (pipeline, etape, totaluri, deals ferestruite, 3 eager-load-uri) = 15, CONSTANT
     * indiferent de câte etape sau deals există. Plafonul de mai jos lasă un pic de
     * marjă peste cele 15 măsurate, dar rămâne mult sub vechiul comportament
     * proporțional cu numărul de etape.
     */
    /**
     * Antetul fiecărei coloane arată suma etapei, iar banda de sinteză o derivă din aceleași
     * coloane. Două invariante, ambele ușor de rupt tăcut:
     *
     *  - suma e pe TOATĂ etapa, nu pe cardurile trimise (plafonate la 50). Dacă cineva o
     *    calculează vreodată din `deals`, antetul ar descrie fereastra, nu etapa — iar pe o
     *    coloană de 52 de carduri diferența e invizibilă fără un test;
     *  - afacerile FĂRĂ valoare contează la `total`, dar adaugă zero la sumă (`coalesce`):
     *    un pipeline cu oportunități neevaluate arată multe carduri și o sumă mică, ceea ce
     *    e adevărat.
     */
    public function test_each_column_reports_the_value_of_the_whole_stage_not_of_the_cards_sent(): void
    {
        TenantContext::run($this->tenant, function (): void {
            // 52 × 5.000 = 260.000, din care doar 50 de carduri ajung pe client.
            for ($i = 0; $i < 52; $i++) {
                $this->dealOn('New', $this->owner, sprintf('Deal %02d', $i));
            }

            $this->dealOn('Qualified', $this->owner, 'Evaluated', 1_250.50);
            $this->dealOn('Qualified', $this->owner, 'Not evaluated yet', null);
        });

        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/deals/board?owner=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.total', 52)
                ->has('columns.0.deals', 50)
                ->where('columns.0.valueTotal', 260000)
                // Două carduri, o singură valoare.
                ->where('columns.1.total', 2)
                ->where('columns.1.valueTotal', 1250.5)
                // Etapă goală: zero, nu `null` — clientul însumează fără verificări.
                ->where('columns.2.valueTotal', 0)
            );
    }

    public function test_the_board_runs_a_constant_number_of_queries_regardless_of_stage_count(): void
    {
        TenantContext::run($this->tenant, function (): void {
            foreach (array_keys($this->stages) as $stageName) {
                for ($i = 0; $i < 4; $i++) {
                    $this->dealOn($stageName, $this->owner, "{$stageName} deal {$i}");
                }
            }
        });

        $this->clearDatabaseTenantContext();

        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/marlin/deals/board?owner=all')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queryCount, "Board query count should stay constant regardless of stage count, got {$queryCount}.");
    }

    public function test_can_per_card_differs_by_role(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->tenant, 'viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->tenant, function () use ($agent): void {
            $this->dealOn('New', $agent, 'Agent-owned deal');
        });

        $this->clearDatabaseTenantContext();

        // Owner: peste orice deal.
        $this->assertCardCan($this->owner, true, true);
        // Manager: peste orice deal.
        $this->assertCardCan($manager, true, true);
        // Agent, pe PROPRIUL deal: poate.
        $this->assertCardCan($agent, true, true);
        // Viewer: niciodată — nici măcar `edit`.
        $this->assertCardCan($viewer, false, false);
    }

    public function test_an_agent_cannot_move_a_colleagues_card(): void
    {
        $owner2 = $this->makeMember($this->tenant, 'owner2@throughput.dev', Permissions::OWNER);
        $agent = $this->makeMember($this->tenant, 'agent2@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->tenant, function () use ($owner2): void {
            $this->dealOn('New', $owner2, 'Owner-owned deal');
        });

        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get('/marlin/deals/board?owner=all')
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.deals.0.can.moveStage', false)
                ->where('columns.0.deals.0.can.edit', false)
            );
    }

    public function test_the_owner_filter_defaults_to_me_for_agents_and_all_for_others(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent3@throughput.dev', Permissions::AGENT);

        $this->actingAs($this->owner)->get('/marlin/deals/board')
            ->assertInertia(fn (Assert $page) => $page->where('ownerFilter', 'all'));

        $this->actingAs($agent)->get('/marlin/deals/board')
            ->assertInertia(fn (Assert $page) => $page->where('ownerFilter', 'me'));
    }

    private function assertCardCan(User $user, bool $edit, bool $moveStage): void
    {
        $this->actingAs($user)->get('/marlin/deals/board?owner=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.deals.0.can.edit', $edit)
                ->where('columns.0.deals.0.can.moveStage', $moveStage)
            );
    }

    private function dealOn(string $stageName, User $owner, string $title, float|int|null $value = 5000): Deal
    {
        $stage = $this->stages[$stageName];

        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $owner->getKey(),
            'title' => $title,
            'value' => $value,
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $owner->getKey();
        $deal->save();

        return $deal;
    }
}
