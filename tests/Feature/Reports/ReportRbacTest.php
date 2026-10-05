<?php

namespace Tests\Feature\Reports;

use App\Models\ReportDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * Matricea §7.4, rândul „Rapoarte programate": Owner/Manager CRUD, Agent „R (doar cele
 * unde e destinatar)", Viewer „—". Îngustarea ABAC trebuie să se aplice ȘI în listă, nu
 * doar pe pagina de detaliu (cerință explicită a task-ului).
 */
class ReportRbacTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $otherAgent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->otherAgent = $this->makeMember($this->marlin, 'demo.other-agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    private function makeReport(array $overrides = []): ReportDefinition
    {
        return TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate(array_merge([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Deal Velocity by Stage',
            'format' => ReportDefinition::FORMAT_CSV,
            'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ], $overrides)));
    }

    /**
     * Graficele de pe `Reports/Index` randează `DealVelocityReport` — o agregare pe TOT
     * pipeline-ul tenantului. Un Agent vede în liste doar propriile înregistrări (§7.4), deci
     * nu are ce căuta la ea: dreptul lui de „R pe rapoartele unde e destinatar" e o excepție
     * acordată per raport, nu un acces la agregatul întreg.
     *
     * Prop-ul e `null` pentru el — nu amânat-și-gol, ci absent: pagina nu randează nici măcar
     * scheletul. Pentru ceilalți e AMÂNAT, deci lipsește din PRIMUL răspuns și apare la
     * reîncărcarea parțială; ambele jumătăți se verifică, fiindcă „lipsește" arată identic
     * dacă te uiți doar la primul.
     */
    public function test_the_index_charts_are_hidden_from_agents_and_deferred_for_everyone_else(): void
    {
        $this->makeReport(['recipients' => ['demo.agent@throughput.dev']]);
        // Raportul citește etapele pipeline-ului implicit: fără ele, `velocity` e gol și
        // testul n-ar verifica forma rândurilor, ci absența lor.
        TenantContext::run($this->marlin, fn () => $this->makeDefaultPipeline($this->marlin));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get('/marlin/reports')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('insights', null));

        foreach ([$this->owner, $this->manager] as $user) {
            // `withHeaders()` se ACUMULEAZĂ pe clientul de test, nu se aplică unei singure
            // cereri: fără golire, a doua trecere prin buclă ar moșteni `X-Inertia` de la
            // reîncărcarea parțială de mai jos și ar primi JSON în loc de view-ul Blade —
            // „Not a valid Inertia response", la o linie care n-are nicio legătură cu cauza.
            $this->flushHeaders();

            $this->actingAs($user)->get('/marlin/reports')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->missing('insights'));

            $version = $this->actingAs($user)
                ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'warmup', 'X-Inertia-Partial-Component' => 'Reports/Index', 'X-Inertia-Partial-Data' => 'insights'])
                ->get('/marlin/reports')
                ->headers->get('x-inertia-version');

            $this->actingAs($user)
                ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Reports/Index', 'X-Inertia-Partial-Data' => 'insights'])
                ->get('/marlin/reports')
                ->assertOk()
                // Un rând per etapă a pipeline-ului implicit, în forma pozițională pe care o
                // consumă și exportul: [pipeline, etapă, zile medii, afaceri, conversie].
                ->assertJsonPath('props.insights.velocity.0.1', 'New')
                ->assertJsonCount(5, 'props.insights.velocity.0');
        }

        $this->flushHeaders();
    }

    public function test_viewer_is_refused_everywhere(): void
    {
        $report = $this->makeReport(['recipients' => ['demo.viewer@throughput.dev']]);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)->get('/marlin/reports')->assertForbidden();
        $this->actingAs($this->viewer)->get("/marlin/reports/{$report->id}")->assertForbidden();
        $this->actingAs($this->viewer)->get('/marlin/reports/create')->assertForbidden();
        $this->actingAs($this->viewer)->post('/marlin/reports/'.$report->id.'/run')->assertForbidden();
    }

    public function test_agent_sees_only_reports_where_they_are_a_recipient_in_the_index(): void
    {
        $mine = $this->makeReport(['name' => 'Mine', 'recipients' => ['demo.agent@throughput.dev']]);
        $notMine = $this->makeReport(['name' => 'Not mine', 'recipients' => ['demo.other-agent@throughput.dev']]);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->get('/marlin/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('reports.0.id', $mine->id)
            ->has('reports', 1));
    }

    public function test_agent_can_view_a_report_where_they_are_a_recipient_but_not_others(): void
    {
        $mine = $this->makeReport(['recipients' => ['demo.agent@throughput.dev']]);
        $notMine = $this->makeReport(['recipients' => ['demo.other-agent@throughput.dev']]);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get("/marlin/reports/{$mine->id}")->assertOk();
        $this->actingAs($this->agent)->get("/marlin/reports/{$notMine->id}")->assertForbidden();
    }

    /**
     * Comparație case-insensitive (§16.1 — recipients tastate liber de Owner/Manager).
     */
    public function test_recipient_matching_is_case_insensitive(): void
    {
        $report = $this->makeReport(['recipients' => ['Demo.Agent@Throughput.dev']]);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get("/marlin/reports/{$report->id}")->assertOk();
    }

    public function test_agent_cannot_create_update_delete_or_run_even_a_report_where_they_are_a_recipient(): void
    {
        $report = $this->makeReport(['recipients' => ['demo.agent@throughput.dev']]);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get('/marlin/reports/create')->assertForbidden();
        $this->actingAs($this->agent)->post('/marlin/reports', [])->assertForbidden();
        $this->actingAs($this->agent)->get("/marlin/reports/{$report->id}/edit")->assertForbidden();
        $this->actingAs($this->agent)->put("/marlin/reports/{$report->id}", [])->assertForbidden();
        $this->actingAs($this->agent)->delete("/marlin/reports/{$report->id}")->assertForbidden();
        $this->actingAs($this->agent)->post("/marlin/reports/{$report->id}/run")->assertForbidden();
    }

    public function test_owner_and_manager_see_and_manage_all_reports_regardless_of_recipients(): void
    {
        $reportA = $this->makeReport(['name' => 'A', 'recipients' => ['demo.agent@throughput.dev']]);
        $reportB = $this->makeReport(['name' => 'B', 'recipients' => ['demo.other-agent@throughput.dev']]);
        $this->clearDatabaseTenantContext();

        foreach ([$this->owner, $this->manager] as $user) {
            $response = $this->actingAs($user)->get('/marlin/reports');
            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->has('reports', 2));

            $this->actingAs($user)->get("/marlin/reports/{$reportA->id}")->assertOk();
            $this->actingAs($user)->get("/marlin/reports/{$reportB->id}")->assertOk();
            $this->actingAs($user)->get('/marlin/reports/create')->assertOk();
        }
    }

    public function test_manager_can_create_edit_and_delete_a_report(): void
    {
        $response = $this->actingAs($this->manager)->post('/marlin/reports', [
            'name' => 'Weekly velocity',
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.manager@throughput.dev'],
        ]);
        $response->assertRedirect();

        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::query()->where('name', 'Weekly velocity')->firstOrFail());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->manager)->put("/marlin/reports/{$report->id}", [
            'name' => 'Weekly velocity (renamed)',
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.manager@throughput.dev'],
        ])->assertRedirect();

        $this->actingAs($this->manager)->delete("/marlin/reports/{$report->id}")->assertRedirect('/marlin/reports');
    }
}
