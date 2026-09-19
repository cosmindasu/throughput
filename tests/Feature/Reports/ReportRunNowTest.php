<?php

namespace Tests\Feature\Reports;

use App\Mail\ReportDeliveryMail;
use App\Models\DealStageEvent;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Database\Factories\DealFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\StageFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * US-REP-02 — „Run now" manual, de la clic până la email + istoric + descărcare.
 * Coada `database` (nu `sync`, `.ai/rules/tenancy.md`): jobul rulează într-un proces
 * separat de cererea care l-a declanșat.
 */
class ReportRunNowTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function (): void {
            $pipeline = (new PipelineFactory)->create();
            $stageA = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'New', 'position' => 1]);
            $stageB = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'Qualified', 'position' => 2]);
            $account = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);
            $deal = (new DealFactory)->create([
                'account_id' => $account->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stageB->id,
                'owner_user_id' => $this->owner->getKey(),
                'created_by' => $this->owner->getKey(),
            ]);

            DealStageEvent::forceCreate([
                'deal_id' => $deal->id,
                'from_stage_id' => $stageA->id,
                'to_stage_id' => $stageB->id,
                'changed_by' => $this->owner->getKey(),
                'changed_at' => now(),
                'duration_in_previous_stage_seconds' => 3600,
            ]);
        });

        $this->clearDatabaseTenantContext();
        Storage::fake('local');
    }

    private function makeReport(): ReportDefinition
    {
        return TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Deal Velocity by Stage',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
    }

    public function test_run_now_queues_a_run_generates_a_file_and_emails_the_recipients(): void
    {
        Mail::fake();
        $report = $this->makeReport();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post("/marlin/reports/{$report->id}/run");
        $response->assertRedirect("/marlin/reports/{$report->id}");

        $run = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $report->getKey())->firstOrFail());
        $this->assertSame(ReportRun::STATUS_QUEUED, $run->status);
        $this->assertSame(ReportRun::TRIGGERED_BY_MANUAL, $run->triggered_by);
        $this->assertSame(1, DB::table('jobs')->count(), '"Run now" must enqueue a job, not run synchronously (queue database, not sync).');
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_SUCCESS, $fresh->status);
        $this->assertNotNull($fresh->file_path);
        Storage::disk('local')->assertExists($fresh->file_path);

        Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail) => $mail->hasTo('demo.owner@throughput.dev'));
    }

    public function test_the_run_appears_in_history_and_can_be_downloaded_by_the_author(): void
    {
        Mail::fake();
        $report = $this->makeReport();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/reports/{$report->id}/run")->assertRedirect();
        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        $response = $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->has('runs', 1)
            ->where('runs.0.status', 'success')
            ->where('runs.0.hasFile', true));

        $run = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $report->getKey())->firstOrFail());
        $this->clearDatabaseTenantContext();

        $download = $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}/runs/{$run->id}/download");
        $download->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('Content-Type'));
    }

    public function test_an_agent_who_is_not_a_recipient_cannot_download_a_run(): void
    {
        Mail::fake();
        $report = $this->makeReport();
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/reports/{$report->id}/run")->assertRedirect();
        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        $run = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $report->getKey())->firstOrFail());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->get("/marlin/reports/{$report->id}/runs/{$run->id}/download")->assertForbidden();
    }

    /**
     * Fix P3 (review) — „gaura considerată cea mai probabilă": un id de rulare care
     * aparține unui ALT raport (același tenant, deci ar trece de RLS/scope) nu trebuie să
     * se descarce prin URL-ul raportului greșit. Verificat deja de `ReportController::download()`
     * (`abort_unless($run->report_definition_id === $report->getKey(), 404)`) — testul lipsea
     * din suita permanentă.
     */
    public function test_downloading_a_run_that_belongs_to_a_different_report_is_a_404(): void
    {
        Mail::fake();
        $reportA = $this->makeReport();
        $reportB = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/reports/{$reportA->id}/run")->assertRedirect();
        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--no-interaction' => true]);

        $runA = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $reportA->getKey())->firstOrFail());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get("/marlin/reports/{$reportB->id}/runs/{$runA->id}/download")
            ->assertNotFound();
    }
}
