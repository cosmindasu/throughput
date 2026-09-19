<?php

namespace App\Jobs\System;

use App\Jobs\Reports\GenerateReportJob;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Reports\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduler-ul de rapoarte (specs.md §16.2 pct. 1-2). Job de SISTEM (`.ai/rules/tenancy.md`,
 * ADR-014 pct. 4, la fel ca `PruneExpiredExportsJob`/`ResetDemoDataJob`): fără tenant,
 * iterează tenanții explicit, cu un context per tenant, pe conexiunea aplicației — NICIODATĂ
 * pe cea cu BYPASSRLS.
 *
 * Rulat ORAR de `routes/console.php` (`Schedule::job(...)->hourly()`), pentru că §16.2 pct. 1
 * cere explicit „verifică ce report_definitions sunt scadente ÎN ORA CURENTĂ" — granularitatea
 * cea mai fină pe care o cere specificația e ora, nu minutul: `schedule_time` se compară doar
 * pe componenta de ORĂ (`ReportSchedule::isDueAt()`), deci a rula mai des n-ar produce nimic
 * în plus, doar interogări suplimentare pe fiecare tenant.
 *
 * `$hourStartIso` — SCALAR, primit din `routes/console.php`, capturat la TICK (când
 * `schedule:run` construiește programul), NU calculat aici la execuție (fix P2, review).
 * Proiectul are UN SINGUR worker de coadă: dacă jobul stă în coadă și abia se execută după
 * granița orei (worker ocupat cu alte joburi), un `CarbonImmutable::now()` apelat ÎN
 * `handle()` ar vedea ora GREȘITĂ — un raport scadent la ora capturată la tick ar fi SĂRIT
 * tăcut, fără eroare și fără rând în `report_runs`, doar pentru că worker-ul a întârziat.
 *
 * PREVENIREA DUBLĂRII — DOUĂ straturi independente:
 *  1. `ShouldBeUnique` + `uniqueFor` sub o oră: dacă `schedule:run` dispecerizează acest job
 *     de două ori în aceeași oră (redeploy, tick de cron suprapus), a doua dispecerizare e
 *     respinsă de lock-ul de coadă înainte să ajungă la `handle()` — exact tiparul deja
 *     folosit de `ResetDemoDataJob`.
 *  2. Verificare la nivel de RÂND, per `report_definition`, SUB `->lock('for no key update')`
 *     (fix P2, review — TOCTOU): fără blocare, două execuții concurente (lock-ul de la (1)
 *     pierdut/expirat) ar putea CITI amândouă „niciun run în fereastră" sub READ COMMITTED
 *     și ar insera amândouă. `report_definitions` e PĂRINTELE lui `report_runs`
 *     (`.ai/rules/tenancy.md`, „Blocarea unui rând părinte") — `FOR NO KEY UPDATE`, NU
 *     `FOR UPDATE`/`lockForUpdate()`: `FOR UPDATE` ar intra în conflict cu `FOR KEY SHARE`,
 *     blocarea pe care Postgres o ia la verificarea FK a INSERT-ului din tabela copil, deci
 *     ar bloca inclusiv inserările NELEGATE de cursa asta. `FOR NO KEY UPDATE` serializează
 *     exact verificarea „e deja o rulare în fereastră", lăsând copiii să treacă.
 */
class DispatchScheduledReportsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** Sub o oră — lasă libera fereastra UMĂTOARE, dar acoperă suprapunerea din aceeași oră. */
    public int $uniqueFor = 3300;

    public function __construct(
        public string $hourStartIso,
    ) {}

    public function handle(): void
    {
        $hourStart = CarbonImmutable::parse($this->hourStartIso);

        Tenant::query()->eachById(function (Tenant $tenant) use ($hourStart): void {
            TenantContext::run($tenant, function () use ($tenant, $hourStart): void {
                ReportDefinition::query()
                    ->where('is_active', true)
                    ->where('schedule_frequency', '!=', ReportDefinition::FREQUENCY_NONE)
                    ->lock('for no key update')
                    ->get()
                    ->each(function (ReportDefinition $definition) use ($tenant, $hourStart): void {
                        if (! ReportSchedule::isDueAt($definition, $hourStart)) {
                            return;
                        }

                        if ($this->alreadyDispatchedThisWindow($definition, $hourStart)) {
                            return;
                        }

                        $run = ReportRun::query()->create([
                            'report_definition_id' => $definition->getKey(),
                            'status' => ReportRun::STATUS_QUEUED,
                            'triggered_by' => ReportRun::TRIGGERED_BY_SCHEDULER,
                        ]);

                        GenerateReportJob::dispatch($tenant->getKey(), $run->getKey())->onQueue('default');
                    });
            });
        });
    }

    private function alreadyDispatchedThisWindow(ReportDefinition $definition, CarbonImmutable $hourStart): bool
    {
        return $definition->reportRuns()
            ->where('triggered_by', ReportRun::TRIGGERED_BY_SCHEDULER)
            ->where('created_at', '>=', $hourStart)
            ->exists();
    }
}
