<?php

namespace Tests\Feature\Reports;

use App\Models\ReportDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Matricea §7.4, rândul „Rapoarte programate": Owner/Manager CRUD, Agent „R (doar cele
 * unde e destinatar)", Viewer „—". Îngustarea ABAC trebuie să se aplice ȘI în listă, nu
 * doar pe pagina de detaliu (cerință explicită a task-ului).
 */
class ReportRbacTest extends TestCase
{
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
