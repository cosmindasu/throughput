<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\GenerateReportJob;
use App\Jobs\System\DispatchScheduledReportsJob;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use App\Support\Reports\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Scadența scheduler-ului (specs.md §16.2 pct. 1) — daily/weekly/monthly, inclusiv
 * clamparea zilei de lună peste ultima zi, și PREVENIREA DUBLĂRII cerută explicit de task.
 */
class ReportScheduleTest extends TestCase
{
    // ── Verificări pure ale ReportSchedule::isDueAt() — fără bază de date ──────────

    public function test_daily_is_due_only_at_the_matching_hour(): void
    {
        $definition = new ReportDefinition([
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => '07:00:00',
        ]);

        $this->assertTrue(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2026-09-21 07:00:00', 'UTC')));
        $this->assertFalse(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2026-09-21 08:00:00', 'UTC')));
    }

    public function test_weekly_is_due_only_on_the_matching_iso_weekday(): void
    {
        $definition = new ReportDefinition([
            'schedule_frequency' => ReportDefinition::FREQUENCY_WEEKLY,
            'schedule_time' => '07:00:00',
            'schedule_day' => 1, // Monday (ISO)
        ]);

        // 2026-09-21 is a Monday.
        $this->assertTrue(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2026-09-21 07:00:00', 'UTC')));
        // 2026-09-22 is a Tuesday.
        $this->assertFalse(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2026-09-22 07:00:00', 'UTC')));
    }

    public function test_monthly_clamps_a_day_beyond_the_end_of_a_shorter_month_to_the_last_day(): void
    {
        $definition = new ReportDefinition([
            'schedule_frequency' => ReportDefinition::FREQUENCY_MONTHLY,
            'schedule_time' => '07:00:00',
            'schedule_day' => 31,
        ]);

        // February 2027 has 28 days — day 31 clamps to the 28th, not "never".
        $this->assertTrue(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2027-02-28 07:00:00', 'UTC')));
        $this->assertFalse(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2027-02-27 07:00:00', 'UTC')));
        // A 31-day month still fires exactly on the 31st.
        $this->assertTrue(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2027-01-31 07:00:00', 'UTC')));
    }

    public function test_none_frequency_is_never_due(): void
    {
        $definition = new ReportDefinition([
            'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
            'schedule_time' => '07:00:00',
        ]);

        $this->assertFalse(ReportSchedule::isDueAt($definition, CarbonImmutable::parse('2026-09-21 07:00:00', 'UTC')));
    }

    // ── DispatchScheduledReportsJob — integrare, cu prevenirea dublării ─────────────

    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
    }

    public function test_it_dispatches_only_reports_due_in_the_current_hour(): void
    {
        Bus::fake();
        $hourStart = CarbonImmutable::parse('2026-09-21 07:00:00', 'UTC');

        $due = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Due now',
            'format' => 'csv',
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => '07:00:00',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));

        TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Not due yet',
            'format' => 'csv',
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => '09:00:00',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));

        TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Inactive but due',
            'format' => 'csv',
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => '07:00:00',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => false,
            'created_by' => $this->owner->getKey(),
        ]));

        (new DispatchScheduledReportsJob($hourStart->toIso8601String()))->handle();

        $runs = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $due->getKey())->get());
        $this->clearDatabaseTenantContext();

        $this->assertCount(1, $runs);
        $this->assertSame(ReportRun::TRIGGERED_BY_SCHEDULER, $runs->first()->triggered_by);
        Bus::assertDispatched(GenerateReportJob::class, 1);
    }

    /**
     * Prevenirea dublării (cerută explicit de task) — scheduler-ul rulat DE DOUĂ ORI în
     * aceeași fereastră orară (redeploy, tick suprapus) nu creează un al doilea
     * `report_runs` pentru același raport.
     *
     * Fix P2 (review) — `$hourStartIso` e acum un SCALAR primit din constructor (nu mai
     * calculat cu `CarbonImmutable::now()` în `handle()`), iar dedup-ul compară
     * `report_runs.created_at` (coloană REALĂ, populată de Postgres cu ceasul REAL de
     * sistem — `useCurrent()`, nu mai decodată dintr-un ULID). Cele DOUĂ dispecerizări de
     * mai jos folosesc, deliberat, ACEEAȘI oră REALĂ curentă (`CarbonImmutable::now()`),
     * nu o dată falsă din viitor: `created_at` al primei rulări nu poate fi „mutat" cu
     * `Carbon::setTestNow()` (e scris de Postgres, nu de PHP), deci un `$hourStartIso` din
     * viitor ar fi făcut dedup-ul să vadă mereu „nicio rulare încă", exact bug-ul din
     * versiunea anterioară a acestui test (rezolvat acum, nu ocolit).
     */
    public function test_running_the_dispatcher_twice_in_the_same_hour_does_not_duplicate_the_run(): void
    {
        Bus::fake();
        $hourStart = CarbonImmutable::now('UTC')->startOfHour();

        $due = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Due now',
            'format' => 'csv',
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => $hourStart->format('H:i:s'),
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));

        (new DispatchScheduledReportsJob($hourStart->toIso8601String()))->handle();
        // Al doilea tick, cu EXACT ACEEAȘI valoare de `$hourStartIso` — simulează precis
        // scenariul „schedule:run suprapus la redeploy" (`ShouldBeUnique` protejează la
        // nivelul cozii; verificarea de-a doua, la nivel de rând, se testează aici direct).
        (new DispatchScheduledReportsJob($hourStart->toIso8601String()))->handle();

        $runs = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $due->getKey())->get());
        $this->clearDatabaseTenantContext();

        $this->assertCount(1, $runs, 'A second tick within the same hour window must not create a second run.');
        Bus::assertDispatched(GenerateReportJob::class, 1);
    }

    public function test_the_next_hour_window_dispatches_again(): void
    {
        Bus::fake();
        $hourStart = CarbonImmutable::now('UTC')->startOfHour();

        $due = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
            'name' => 'Hourly-ish for the test',
            'format' => 'csv',
            'schedule_frequency' => ReportDefinition::FREQUENCY_DAILY,
            'schedule_time' => $hourStart->format('H:i:s'),
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));

        (new DispatchScheduledReportsJob($hourStart->toIso8601String()))->handle();

        // O fereastră ULTERIOARĂ, exprimată prin valoarea trecută jobului (nu prin ceasul
        // real al sistemului, care nu poate fi „avansat" în test) — suficient pentru
        // mecanismul de dedup, care compară `created_at` al rulărilor anterioare (reale)
        // cu `$hourStartIso` primit, niciodată cu ceasul de sistem direct.
        $laterHourStart = $hourStart->addDay();
        (new DispatchScheduledReportsJob($laterHourStart->toIso8601String()))->handle();

        $runs = TenantContext::run($this->marlin, fn () => ReportRun::query()->where('report_definition_id', $due->getKey())->get());
        $this->clearDatabaseTenantContext();

        $this->assertCount(2, $runs, 'A later due window must dispatch a new run.');
    }
}
