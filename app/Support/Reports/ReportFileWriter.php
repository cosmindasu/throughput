<?php

namespace App\Support\Reports;

use App\Enums\ReportFormat;
use App\Support\Exports\CsvExporter;
use App\Support\Exports\ExportableList;
use App\Support\Exports\ExportQueryChunker;
use App\Support\Exports\PdfExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Scrierea fișierului unui `report_runs` (specs.md §16.2 pct. 3), pentru cele DOUĂ familii
 * de sursă (§16.1): built-in (rânduri deja agregate, `BuiltInReport::rows()`) și
 * `saved_view_export` (o `ExportableList` + un `Builder`, exact mecanismul de export de
 * liste existent — §13.2, REFOLOSIT, nu reimplementat).
 *
 * `csv`/`pdf` pentru `saved_view_export` deleagă STRICT la `CsvExporter`/`PdfExporter`
 * (interzis să le modific — le citesc și le refolosesc). `xlsx` nu are echivalent acolo
 * (`App\Support\Exports\ExportFormat` suportă doar csv/pdf — e enum-ul mecanismului de
 * export de liste, al altui lot), deci rapoartele au propriul `App\Enums\ReportFormat`
 * și propriul writer xlsx (`ArrayExport`, `maatwebsite/excel`, deja în composer.json).
 *
 * Rândurile built-in sunt mereu mici (agregate pe etapă/pipeline sau locație/categorie,
 * §16.3) — niciun risc de memorie la materializarea lor într-un array PHP. Pentru
 * `saved_view_export`, rândurile de-a lungul lui `ExportQueryChunker` sunt materializate în
 * memorie DOAR pentru `xlsx` (ca la `PdfExporter`, care face identic pentru `pdf`) — un
 * export xlsx foarte mare ar avea același profil de memorie ca un export PDF, dar FĂRĂ
 * plafonul lui `export_pdf_max_rows` (acel plafon e specific PDF-ului în specs.md §16.2
 * pct. 5 și în `config/throughput.php`, fișier interzis acestui lot). Risc cunoscut, notat
 * în raportul lotului K — nu rezolvat aici prin adăugarea unui plafon nou fără aprobare.
 */
final class ReportFileWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function writeRows(array $headers, array $rows, ReportFormat $format, string $path, string $title, string $workspaceName): void
    {
        match ($format) {
            ReportFormat::Csv => Storage::disk('local')->put($path, self::toCsvString($headers, $rows)),
            ReportFormat::Xlsx => Excel::store(new ArrayExport($headers, $rows), $path, 'local'),
            ReportFormat::Pdf => Pdf::view('reports.pdf.built-in', [
                'title' => $title,
                'headers' => $headers,
                'rows' => $rows,
                'workspaceName' => $workspaceName,
                'generatedAt' => now(),
            ])
                ->driver('dompdf')
                ->orientation(Orientation::Landscape)
                ->format(Format::A4)
                ->disk('local')
                ->save($path),
        };
    }

    /**
     * Sursa `saved_view_export` — `$list`/`$query` vin din `ExportableResources::resolve()`
     * + `ResourceList::query()`, exact ca `App\Jobs\Exports\ExportListJob`.
     *
     * @return int numărul de rânduri scrise
     */
    public static function writeFromList(
        ExportableList $list,
        Builder $query,
        ReportFormat $format,
        string $path,
        string $workspaceName,
    ): int {
        return match ($format) {
            ReportFormat::Csv => self::writeListCsv($list, $query, $path),
            ReportFormat::Xlsx => self::writeListXlsx($list, $query, $path),
            ReportFormat::Pdf => self::writeListPdf($list, $query, $path, $workspaceName),
        };
    }

    private static function writeListCsv(ExportableList $list, Builder $query, string $path): int
    {
        Storage::disk('local')->put($path, CsvExporter::toString($list, $query));

        return (clone $query)->toBase()->getCountForPagination();
    }

    private static function writeListPdf(ExportableList $list, Builder $query, string $path, string $workspaceName): int
    {
        PdfExporter::save($list, $query, $path, $workspaceName, []);

        return (clone $query)->toBase()->getCountForPagination();
    }

    private static function writeListXlsx(ExportableList $list, Builder $query, string $path): int
    {
        $headers = $list->exportHeaders();
        $rows = [];

        // Identic cu `PdfExporter::save()` (docblock-ul clasei) — `ExportQueryChunker`, NU
        // `cursor()` (ignoră tăcut `with()`, capcana N+1 din Faza 3).
        ExportQueryChunker::each($query, function ($chunk) use (&$rows, $list): void {
            foreach ($chunk as $row) {
                $rows[] = $list->exportRow($row);
            }
        });

        Excel::store(new ArrayExport($headers, $rows), $path, 'local');

        return count($rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    private static function toCsvString(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream to write the CSV report.');
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string|int|float|null $value) => is_string($value) ? self::escapeFormula($value) : $value,
                $row,
            ));
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }

    /**
     * Aceeași protecție OWASP CSV/Formula Injection ca `App\Support\Exports\CsvExporter`
     * (P1-001) — duplicată aici deliberat (metoda sursă e `private`, iar clasa e pe lista
     * de fișiere interzise acestui lot), nu o coincidență de nume.
     */
    private static function escapeFormula(string $value): string
    {
        foreach (['=', '+', '-', '@', "\t", "\r"] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
