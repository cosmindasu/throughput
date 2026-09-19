<?php

namespace App\Support\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Adaptor minimal `maatwebsite/excel` pentru un array de rânduri deja materializat —
 * folosit de `ReportFileWriter` pentru formatul `xlsx` (§16.2 pct. 3), atât pentru
 * rapoartele built-in (rânduri agregate, mereu mici), cât și pentru `saved_view_export`
 * (rânduri strânse în prealabil prin `ExportQueryChunker`, exact ca `PdfExporter`).
 *
 * `maatwebsite/excel` nu era folosit încă în `app/` — asta e prima integrare (composer.json
 * îl are deja, `^4.0`, pentru xlsx).
 */
final class ArrayExport implements FromArray, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
