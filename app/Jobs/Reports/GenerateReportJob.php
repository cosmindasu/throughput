<?php

namespace App\Jobs\Reports;

use App\Enums\ReportFormat;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Exports\ExportableResources;
use App\Support\JobErrorMessage;
use App\Support\Reports\BuiltInReports;
use App\Support\Reports\ReportFileWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Rulează un `report_runs` (specs.md §16.2 pct. 2-3, 5): `queued` → `running` →
 * `success`/`failed`, generează fișierul, apoi dispecerizează `DeliverReportJob` DOAR la
 * succes (niciodată la eșec — un fișier corupt/gol nu ajunge pe email, pct. 5).
 *
 * Job de TENANT (§6.3, ADR-014 pct. 4): constructorul primește SCALARI (`tenantId`,
 * `reportRunId`), niciodată modele. Tipar IDENTIC cu `App\Jobs\Exports\ExportListJob`
 * (citit ca model de structură, nu copiat orbește): DOUĂ tranzacții scurte prin
 * `TenantContext::run()`, FĂRĂ `middleware(): [new ApplyTenantContextToJob]` — acel
 * middleware ar înfășura tot `handle()` într-o singură tranzacție, deci `running` nu s-ar
 * comite niciodată separat de starea finală, iar polling-ul din `Reports/Show.tsx` n-ar
 * vedea niciodată starea intermediară (P1-003, `ExportListJob`).
 *
 * `$tries`/`$timeout` FINITE: un job ucis abrupt (OOM, `pcntl_alarm`) nu ajunge în niciun
 * `catch` de mai jos — `failed()` e plasa de siguranță, apelată dintr-un worker sănătos
 * după epuizarea reîncercărilor.
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public string $tenantId,
        public string $reportRunId,
    ) {}

    public function handle(): void
    {
        $found = TenantContext::run($this->tenantId, function (): bool {
            $run = ReportRun::query()->find($this->reportRunId);

            if ($run === null) {
                return false;
            }

            $run->update(['status' => ReportRun::STATUS_RUNNING, 'started_at' => now()]);

            return true;
        });

        if (! $found) {
            return;
        }

        TenantContext::run($this->tenantId, function (): void {
            $run = ReportRun::query()->with('reportDefinition.savedView')->find($this->reportRunId);

            if ($run === null) {
                return;
            }

            $definition = $run->reportDefinition;

            if ($definition === null) {
                $run->update([
                    'status' => ReportRun::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_message' => JobErrorMessage::encode('job_errors.report.definition_missing'),
                ]);

                return;
            }

            try {
                $format = ReportFormat::from($definition->format);
                $workspaceName = Tenant::query()->find($this->tenantId)?->name ?? 'Workspace';
                $path = "reports/{$this->tenantId}/{$run->getKey()}.{$format->value}";

                $rowCount = $definition->isBuiltIn()
                    ? $this->generateBuiltIn($definition, $format, $path, $workspaceName)
                    : $this->generateFromSavedView($definition, $format, $path, $workspaceName);

                $run->update([
                    'status' => ReportRun::STATUS_SUCCESS,
                    'finished_at' => now(),
                    'file_path' => $path,
                    'row_count' => $rowCount,
                ]);

                // §16.2 pct. 4 — livrarea e un SECOND job, doar la succes (never pe eșec, pct. 5).
                // ADR-022, FR-I18N-05 — locale-ul se rezolvă AICI, la dispecerizare (nu în
                // `DeliverReportJob::handle()`), din creatorul definiției: destinatarii
                // (`recipients`) sunt adrese arbitrare, fără cont, deci fără `users.locale`
                // propriu — creatorul e aceeași persoană „efectivă" deja folosită mai sus în
                // `generateFromSavedView()` pentru „cine e «me» într-un filtru salvat", extinsă
                // aici la limbă, prin aceeași logică de aproximare rezonabilă.
                DeliverReportJob::dispatch(
                    $this->tenantId,
                    $run->getKey(),
                    $definition->createdBy?->locale ?? 'en',
                )->onQueue('default');
            } catch (Throwable $e) {
                $run->update([
                    'status' => ReportRun::STATUS_FAILED,
                    'finished_at' => now(),
                    // I18N-03 — `ReportRowCapExceededException` (mai jos) poartă DOUĂ
                    // reprezentări separate ale aceluiași eșec: `getMessage()`, engleză,
                    // pentru `report()`/Sentry, și `encodedColumnValue`, cheia codificată
                    // pentru coloană — vezi docblock-ul excepției. Orice altă `Throwable`
                    // rămâne pe calea veche (`getMessage()` brut): nu e în lista celor ~12
                    // literale ale acestui lot, iar un mesaj de excepție intern arbitrar
                    // nu se poate cataloga static oricum.
                    'error_message' => $e instanceof ReportRowCapExceededException
                        ? $e->encodedColumnValue
                        : $e->getMessage(),
                ]);

                report($e);
            }
        });
    }

    /**
     * @return int row_count
     */
    private function generateBuiltIn(ReportDefinition $definition, ReportFormat $format, string $path, string $workspaceName): int
    {
        $report = BuiltInReports::resolve($definition->report_type);
        $rows = $report->rows();

        // Plafoane PDF/xlsx (specs.md §16.2 pct. 5 pentru PDF; xlsx e simetric, fix P1
        // review — vezi `assertWithinXlsxCap()`) — defensiv aici: un raport built-in
        // agregat nu se apropie realist de niciun plafon (dozens de rânduri, nu mii), dar
        // verificarea costă o comparație și acoperă orice creștere neașteptată fără OOM tăcut.
        if ($format === ReportFormat::Pdf) {
            $this->assertWithinPdfCap(count($rows));
        } elseif ($format === ReportFormat::Xlsx) {
            $this->assertWithinXlsxCap(count($rows));
        }

        ReportFileWriter::writeRows($report->columns(), $rows, $format, $path, $report->title(), $workspaceName);

        return count($rows);
    }

    /**
     * §16.1 — sursă `saved_view_export`: rulează filtrele vederii peste lista ei de resurse,
     * refolosind STRICT mecanismul existent de export (`ExportableResources`,
     * `ExportQueryChunker`, prin `ReportFileWriter::writeFromList()`).
     *
     * DECIZIE — cine e „me" într-un filtru salvat (ex: `owner=me`, dacă vederea nu l-a
     * pinnuit la salvare, vezi `ResourceList::pinRoleDependentFiltersForSharing()`)? O
     * rulare programată n-are o sesiune HTTP, deci nu există un utilizator „curent" real.
     * Aleg autorul definiției de raport (`created_by`, singurul rol care poate crea/edita
     * rapoarte — `reports.manage`, doar Owner/Manager): raportul arată ce ar vedea EL,
     * consecvent cu semantica „vederea reflectă scopul efectiv al autorului ei" deja
     * stabilită pentru vizualizările salvate (P2-004).
     */
    private function generateFromSavedView(ReportDefinition $definition, ReportFormat $format, string $path, string $workspaceName): int
    {
        $savedView = $definition->savedView;

        if ($savedView === null) {
            throw new \RuntimeException('This report\'s saved view no longer exists.');
        }

        $list = ExportableResources::resolve($savedView->resource_type);
        $listQuery = $list->fromState(['filter' => $savedView->filters, 'sort' => $savedView->sort]);
        $actingUser = $definition->createdBy;
        $query = $list->query($listQuery, $actingUser);

        if ($format === ReportFormat::Pdf) {
            $this->assertWithinPdfCap((clone $query)->toBase()->getCountForPagination());
        } elseif ($format === ReportFormat::Xlsx) {
            $this->assertWithinXlsxCap((clone $query)->toBase()->getCountForPagination());
        }

        return ReportFileWriter::writeFromList($list, $query, $format, $path, $workspaceName);
    }

    private function assertWithinPdfCap(int $count): void
    {
        $cap = (int) config('throughput.limits.export_pdf_max_rows');

        if ($count > $cap) {
            throw new ReportRowCapExceededException(
                "This report has {$count} rows; PDF is capped at {$cap}. Use CSV or XLSX for larger reports.",
                JobErrorMessage::encode('job_errors.report.pdf_row_cap_exceeded', ['count' => $count, 'cap' => $cap]),
            );
        }
    }

    /**
     * Fix P1 (review) — cheia `throughput.limits.export_xlsx_max_rows` (5.000, adăugată în
     * config DUPĂ implementarea inițială a acestui lot) nu era cablată nicăieri.
     * `ReportFileWriter::writeListXlsx()`/`writeRows()` (formatul `xlsx`) materializează
     * TOATE rândurile într-un array PHP înainte de `Excel::store()`, exact ca `PdfExporter`
     * pentru `pdf` — același profil de memorie, deci același risc de OOM pe un tenant mare
     * (măsurat: tenantul-vitrină are 30.000 de comenzi; DomPDF omoară deja containerul la
     * 750 de rânduri pe bugetul de memorie al proiectului, `.ai/rules/project.md`).
     */
    private function assertWithinXlsxCap(int $count): void
    {
        $cap = (int) config('throughput.limits.export_xlsx_max_rows');

        if ($count > $cap) {
            throw new ReportRowCapExceededException(
                "This report has {$count} rows; XLSX is capped at {$cap}. Use CSV for larger reports.",
                JobErrorMessage::encode('job_errors.report.xlsx_row_cap_exceeded', ['count' => $count, 'cap' => $cap]),
            );
        }
    }

    /**
     * Plasă de siguranță (ca `ExportListJob::failed()`) — pentru un job ucis abrupt (OOM,
     * timeout) care nu ajunge în niciun `catch` de mai sus.
     */
    public function failed(Throwable $e): void
    {
        TenantContext::run($this->tenantId, function (): void {
            $run = ReportRun::query()->find($this->reportRunId);

            if ($run === null || $run->status === ReportRun::STATUS_SUCCESS) {
                return;
            }

            $run->update([
                'status' => ReportRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => JobErrorMessage::encode('job_errors.report.generation_failed'),
            ]);
        });

        report($e);
    }
}

/**
 * I18N-03 — plasă a lui `assertWithinPdfCap()`/`assertWithinXlsxCap()` de mai sus: singurele
 * două excepții ARUNCATE de acest job cu un mesaj propriu, în engleză, care CHIAR trebuie să
 * ajungă în DOUĂ locuri diferite, cu conținut diferit:
 *
 *  - `getMessage()` (moștenit din `RuntimeException`) — text englez, citit de `report()`/
 *    Sentry; rămâne engleză mereu, e pentru operator, nu pentru interfață;
 *  - `encodedColumnValue` — cheia de catalog codificată (`JobErrorMessage::encode()`),
 *    scrisă direct pe `report_runs.error_message`, tradusă abia la randare
 *    (`ReportRunResource`), în locale-ul cererii.
 *
 * Fără cele două reprezentări separate, catch-ul generic din `handle()` (`$e->getMessage()`)
 * ar fi scris fie text englez brut pe coloană (regresie I18N-03), fie JSON-ul codificat în
 * log-uri (opac în Sentry) — niciuna dintre ele corectă pentru amândouă destinațiile.
 *
 * Definită în ACEST fișier, nu într-o clasă separată: excepția e aruncată și prinsă exclusiv
 * aici, în `GenerateReportJob` — n-are niciun alt apelant care s-o rezolve prin autoload
 * independent.
 */
final class ReportRowCapExceededException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $encodedColumnValue)
    {
        parent::__construct($message);
    }
}
