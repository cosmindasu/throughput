<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\DeliverReportJob;
use App\Jobs\Reports\GenerateReportJob;
use App\Mail\ReportDeliveryMail;
use App\Models\DealStageEvent;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\JobErrorMessage;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Database\Factories\DealFactory;
use Database\Factories\PipelineFactory;
use Database\Factories\StageFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `App\Jobs\Reports\GenerateReportJob` — cele trei formate (csv/xlsx/pdf), plafonul PDF
 * (specs.md §16.2 pct. 5), și scenariul Gherkin 2 din US-REP-01: „rularea programată
 * eșuează... niciun email nu a fost trimis".
 */
class GenerateReportJobTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        Storage::fake('local');
    }

    private function seedTwoStageEvent(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $pipeline = (new PipelineFactory)->create();
            $stageA = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'New', 'position' => 1]);
            $stageB = (new StageFactory)->create(['pipeline_id' => $pipeline->id, 'name' => 'Qualified', 'position' => 2]);

            DealStageEvent::forceCreate([
                'deal_id' => (new DealFactory)->create([
                    'account_id' => (new AccountFactory)->create(['created_by' => $this->owner->getKey()])->id,
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stageB->id,
                    'owner_user_id' => $this->owner->getKey(),
                    'created_by' => $this->owner->getKey(),
                ])->id,
                'from_stage_id' => $stageA->id,
                'to_stage_id' => $stageB->id,
                'changed_by' => $this->owner->getKey(),
                'changed_at' => now(),
                'duration_in_previous_stage_seconds' => 3600,
            ]);
        });
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

    private function makeQueuedRun(ReportDefinition $report): ReportRun
    {
        return TenantContext::run($this->marlin, fn () => ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_QUEUED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
        ]));
    }

    #[DataProvider('formats')]
    public function test_it_generates_a_file_in_each_supported_format(string $format, string $extension): void
    {
        $this->seedTwoStageEvent();
        $report = $this->makeReport(['format' => $format]);
        $run = $this->makeQueuedRun($report);

        Bus::fake([DeliverReportJob::class]);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_SUCCESS, $fresh->status);
        $this->assertSame(2, $fresh->row_count, 'Two stages ("New", "Qualified") means two aggregate rows.');
        $this->assertNotNull($fresh->file_path);
        $this->assertStringEndsWith(".{$extension}", $fresh->file_path);
        Storage::disk('local')->assertExists($fresh->file_path);

        Bus::assertDispatched(DeliverReportJob::class);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function formats(): array
    {
        return [
            'csv' => ['csv', 'csv'],
            'xlsx' => ['xlsx', 'xlsx'],
            'pdf' => ['pdf', 'pdf'],
        ];
    }

    /**
     * US-REP-01, al doilea scenariu Gherkin: eșecul se marchează `failed`, cu mesaj, și
     * NU se trimite niciun email.
     */
    public function test_a_failed_generation_does_not_dispatch_delivery_or_send_email(): void
    {
        Mail::fake();
        Bus::fake([DeliverReportJob::class]);

        // Sursă `saved_view_export` fără `saved_view_id` valid (vederea nu există) —
        // forțează eroarea din `GenerateReportJob::generateFromSavedView()`.
        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'saved_view_id' => null,
            'name' => 'Broken report',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_FAILED, $fresh->status);
        // P2 (lot i18n, „error_message brut în catch-all-uri") — `RuntimeException`
        // proprie a jobului (mesajul englez de mai sus, „This report's saved view no
        // longer exists.") NU mai ajunge brut pe coloană: ramura „orice altă Throwable"
        // a ternarului din `GenerateReportJob::handle()` scrie acum cheia generică
        // codificată, tradusă abia la randare (`ReportRunResource`).
        $this->assertSame(JobErrorMessage::encode('job_errors.report.unexpected'), $fresh->error_message);
        $this->assertSame(
            'This report failed due to an unexpected error. Try again or contact support if it keeps happening.',
            JobErrorMessage::render($fresh->error_message),
        );
        $this->assertNull($fresh->file_path);

        Bus::assertNotDispatched(DeliverReportJob::class);
        Mail::assertNothingSent();
    }

    /**
     * Plafonul PDF (specs.md §16.2 pct. 5, `throughput.limits.export_pdf_max_rows`) —
     * eșuează curat, cu mesaj, fără fișier și fără email, nu cu OOM.
     */
    public function test_pdf_generation_over_the_row_cap_fails_cleanly_without_a_file(): void
    {
        config(['throughput.limits.export_pdf_max_rows' => 1]);
        Mail::fake();
        Bus::fake([DeliverReportJob::class]);

        $this->seedTwoStageEvent(); // produce 2 rânduri agregate — peste plafonul de 1.
        $report = $this->makeReport(['format' => 'pdf']);
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_FAILED, $fresh->status);
        // I18N-03 — `error_message` e o cheie codificată (`JobErrorMessage`); afirmă pe
        // valoarea RANDATĂ, exact ce ar produce `ReportRunResource`.
        $this->assertStringContainsString('capped at 1', JobErrorMessage::render($fresh->error_message));
        $this->assertNull($fresh->file_path);

        Bus::assertNotDispatched(DeliverReportJob::class);
        Mail::assertNothingSent();
    }

    /**
     * Fix P1 (review) — plafonul xlsx (`throughput.limits.export_xlsx_max_rows`) nu era
     * cablat nicăieri: `writeRows()`/`writeListXlsx()` materializează toate rândurile
     * într-un array PHP înainte de `Excel::store()`, exact ca `PdfExporter` pentru pdf —
     * același risc de memorie, fără plafon.
     */
    public function test_xlsx_generation_over_the_row_cap_fails_cleanly_without_a_file(): void
    {
        config(['throughput.limits.export_xlsx_max_rows' => 1]);
        Mail::fake();
        Bus::fake([DeliverReportJob::class]);

        $this->seedTwoStageEvent(); // produce 2 rânduri agregate — peste plafonul de 1.
        $report = $this->makeReport(['format' => 'xlsx']);
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('capped at 1', JobErrorMessage::render($fresh->error_message));
        $this->assertNull($fresh->file_path);

        Bus::assertNotDispatched(DeliverReportJob::class);
        Mail::assertNothingSent();
    }

    public function test_delivery_job_sends_the_generated_file_as_an_attachment(): void
    {
        Mail::fake();

        $this->seedTwoStageEvent();
        $report = $this->makeReport(['format' => 'csv', 'recipients' => ['demo.owner@throughput.dev', 'external@example.com']]);
        $run = $this->makeQueuedRun($report);

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        // GenerateReportJob a dispecerizat DeliverReportJob real (fără Bus::fake aici) —
        // rulăm handle() direct, ca la ExportListJob/PruneExpiredExportsJob (test de job,
        // nu neapărat de coadă), pe RUN-ul proaspăt marcat "success".
        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        (new DeliverReportJob($this->marlin->getKey(), $fresh->getKey()))->handle();

        Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) {
            return $mail->hasTo('demo.owner@throughput.dev') && $mail->hasTo('external@example.com');
        });

        // Fix P3 (review) — `X-Throughput-Tenant-Id` trebuie prezent pe mesaj, altfel
        // `DemoInterceptingTransport` scrie rândul din „Sent Emails" cu `tenant_id = null`.
        Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) {
            return ($mail->headers()->text['X-Throughput-Tenant-Id'] ?? null) === $this->marlin->getKey();
        });
    }
}
