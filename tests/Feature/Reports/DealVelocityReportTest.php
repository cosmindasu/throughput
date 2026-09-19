<?php

namespace Tests\Feature\Reports;

use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\ReportDefinition;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use App\Support\Reports\DealVelocityReport;
use Database\Factories\AccountFactory;
use Database\Factories\DealFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\StageFactory;
use Tests\TestCase;

/**
 * „Deal Velocity by Stage" (specs.md §16.3) — cifre verificabile pe date semănate exact,
 * nu doar „nu dă eroare" (cerință explicită a task-ului).
 */
class DealVelocityReportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    /**
     * Fixture-ul cu cifre exacte (vezi calculul în raportul lotului K):
     *
     * - Etapa "New": 3 deals au ajuns (A, B, C); durata medie ÎN "New" = (1 zi + 3 zile)/2 = 2.0 zile
     *   (deal C n-a ieșit din "New" încă, deci nu contribuie la durata medie, doar la "reached").
     * - Etapa "Qualified": 2 deals au ajuns (A, B); durata medie ÎN "Qualified" = 3.0 zile (doar A a ieșit).
     * - Etapa "Won": 1 deal a ajuns (A); etapă terminală, fără durată medie (nicio tranziție de ieșire).
     * - Conversie New → Qualified: 2 din 3 = 66.7%.
     * - Conversie Qualified → Won: 1 din 2 = 50.0%.
     */
    private function seedFixture(): array
    {
        return TenantContext::run($this->marlin, function (): array {
            $pipeline = (new PipelineFactory)->create();
            $newStage = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'New', 'position' => 1]);
            $qualifiedStage = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'Qualified', 'position' => 2]);
            $wonStage = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'Won', 'position' => 3, 'is_won' => true]);

            $account = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);

            $makeDeal = fn (string $title) => (new DealFactory)->create([
                'account_id' => $account->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $wonStage->id, // ireelevant pentru raport — proiecția curentă nu contează, doar istoricul
                'owner_user_id' => $this->owner->getKey(),
                'created_by' => $this->owner->getKey(),
                'title' => $title,
            ]);

            $dealA = $makeDeal('Deal A');
            $dealB = $makeDeal('Deal B');
            $dealC = $makeDeal('Deal C');

            $t0 = now()->subDays(10);

            // Deal A: New (1 zi) → Qualified (3 zile) → Won.
            DealStageEvent::forceCreate(['deal_id' => $dealA->id, 'from_stage_id' => null, 'to_stage_id' => $newStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0, 'duration_in_previous_stage_seconds' => null]);
            DealStageEvent::forceCreate(['deal_id' => $dealA->id, 'from_stage_id' => $newStage->id, 'to_stage_id' => $qualifiedStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0->copy()->addDay(), 'duration_in_previous_stage_seconds' => 86400]);
            DealStageEvent::forceCreate(['deal_id' => $dealA->id, 'from_stage_id' => $qualifiedStage->id, 'to_stage_id' => $wonStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0->copy()->addDay()->addDays(3), 'duration_in_previous_stage_seconds' => 259200]);

            // Deal B: New (3 zile) → Qualified (rămâne acolo).
            DealStageEvent::forceCreate(['deal_id' => $dealB->id, 'from_stage_id' => null, 'to_stage_id' => $newStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0, 'duration_in_previous_stage_seconds' => null]);
            DealStageEvent::forceCreate(['deal_id' => $dealB->id, 'from_stage_id' => $newStage->id, 'to_stage_id' => $qualifiedStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0->copy()->addDays(3), 'duration_in_previous_stage_seconds' => 259200]);

            // Deal C: New (rămâne acolo, niciodată nu a ieșit).
            DealStageEvent::forceCreate(['deal_id' => $dealC->id, 'from_stage_id' => null, 'to_stage_id' => $newStage->id, 'changed_by' => $this->owner->getKey(), 'changed_at' => $t0, 'duration_in_previous_stage_seconds' => null]);

            return compact('pipeline', 'newStage', 'qualifiedStage', 'wonStage');
        });
    }

    public function test_it_computes_average_days_in_stage_reached_counts_and_conversion_rates(): void
    {
        $fixture = $this->seedFixture();

        $rows = TenantContext::run($this->marlin, fn () => (new DealVelocityReport)->rows());
        $this->clearDatabaseTenantContext();

        $byStage = collect($rows)->keyBy(fn (array $row) => $row[1]);

        $newRow = $byStage->get('New');
        $this->assertSame(2.0, $newRow[2], 'Avg days in "New" should be (1+3)/2 = 2.0.');
        $this->assertSame(3, $newRow[3], '3 deals reached "New" (A, B, C).');
        $this->assertSame(66.7, $newRow[4], 'Conversion New→Qualified: 2 of 3 = 66.7%.');

        $qualifiedRow = $byStage->get('Qualified');
        $this->assertSame(3.0, $qualifiedRow[2], 'Avg days in "Qualified" should be 3.0 (only deal A left it).');
        $this->assertSame(2, $qualifiedRow[3], '2 deals reached "Qualified" (A, B).');
        $this->assertSame(50.0, $qualifiedRow[4], 'Conversion Qualified→Won: 1 of 2 = 50.0%.');

        $wonRow = $byStage->get('Won');
        $this->assertNull($wonRow[2], 'Terminal stage with no outgoing transition has no average duration.');
        $this->assertSame(1, $wonRow[3], '1 deal reached "Won" (A).');
        $this->assertNull($wonRow[4], 'Terminal stage has no next stage to convert into.');
    }

    public function test_run_now_shows_the_same_numbers_synchronously_in_the_page(): void
    {
        $this->seedFixture();
        $this->clearDatabaseTenantContext();

        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Deal Velocity by Stage',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->where('builtInPreview.totalRows', 3)
            ->has('builtInPreview.rows', 3));
    }
}
