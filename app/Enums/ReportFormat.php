<?php

namespace App\Enums;

/**
 * Formatul unui `report_definitions.format` (specs.md §16.1). Distinct de
 * `App\Support\Exports\ExportFormat` (deliberat — acela e al mecanismului de export de
 * liste, aparține unui alt lot, `csv`/`pdf` doar): rapoartele suportă și `xlsx`
 * (§16.2 pct. 3, `maatwebsite/excel`, deja în `composer.json`), deci au nevoie de propriul
 * enum, nu de o extindere a celui interzis.
 */
enum ReportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }
}
