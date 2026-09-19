<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Enums\Orientation;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Scrierea unui export ca PDF (§13.5, Orders) — mirror-ul lui `CsvExporter`, dar niciodată
 * sincron: chiar sub pragul de export CSV, formatul `pdf` pornește întotdeauna operația în
 * coadă (`App\Jobs\Exports\ExportListJob`, ADR-013 — randarea DomPDF ține CPU-ul mai mult
 * decât un `SELECT`, deci n-are ce căuta în tranzacția cererii).
 *
 * Driverul e ales EXPLICIT aici (`->driver('dompdf')`), niciodată implicit din
 * `config('laravel-pdf.driver')`: implicitul pachetului rămâne liber pentru facturile din
 * Faza 5, care pot alege alt driver fără să atingă exportul de liste.
 *
 * Fără resurse remote (`laravel-pdf.dompdf.is_remote_enabled`, implicit `false` — nepublicat,
 * citit direct din config-ul pachetului), fără CSS peste nivelul 2.1 (fără flex/grid, doar
 * ce înțelege DomPDF), font `DejaVu Sans` (diacritice), A4 landscape.
 */
final class PdfExporter
{
    /**
     * @param  array<string, string>  $filters  Filtrele aplicate (`ListQuery::toArray()['filter']`), afișate în antet.
     */
    public static function save(
        ExportableList $list,
        Builder $query,
        string $path,
        string $workspaceName,
        array $filters,
    ): void {
        $rows = [];

        foreach ($query->cursor() as $row) {
            $rows[] = $list->exportRow($row);
        }

        Pdf::view('exports.pdf.list', [
            'headers' => $list->exportHeaders(),
            'rows' => $rows,
            'workspaceName' => $workspaceName,
            'filters' => self::humanizeFilters($filters),
            'generatedAt' => now(),
        ])
            ->driver('dompdf')
            ->orientation(Orientation::Landscape)
            ->format(Format::A4)
            ->disk('local')
            ->save($path);
    }

    /**
     * P3 (code review) — etichete umane în antet („Status: draft"), nu cheile brute din
     * URL („status=draft"). `q` e special-cazat la „Search" (așa apare pe fiecare listă în
     * UI); restul se capitalizează și înlocuiesc underscore-urile cu spații — generic,
     * fiindcă `PdfExporter` nu știe ce resursă exportă.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private static function humanizeFilters(array $filters): array
    {
        $specialCased = ['q' => 'Search'];
        $humanized = [];

        foreach ($filters as $key => $value) {
            $label = $specialCased[$key] ?? Str::of($key)->replace('_', ' ')->ucfirst()->toString();
            $humanized[$label] = $value;
        }

        return $humanized;
    }
}
