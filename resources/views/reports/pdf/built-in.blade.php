{{--
    Șablon PDF pentru rapoartele BUILT-IN (specs.md §16.3) — `App\Support\Reports\ReportFileWriter`.

    Mirror deliberat, minimal, al `resources/views/exports/pdf/list.blade.php` (§13.5): NU e o
    editare a acelui fișier (interzis pentru acest lot), ci un șablon propriu, necesar pentru că
    `PdfExporter::save()` cere un `ExportableList`+`Builder` Eloquent, iar un raport built-in e
    deja un array de rânduri agregate (`App\Support\Reports\BuiltInReport::rows()`), fără nicio
    interogare de reluat. Aceleași convenții DomPDF: CSS 2.1, fără resurse remote, `DejaVu Sans`.

    I18N (FR-I18N-04, ADR-022) — `$title`/`$headers` vin deja traduse din apelant
    (`App\Support\Reports\BuiltInReport::title()`/`columns()`, catalog `lang/{en,fr}/reports.php`
    — vezi `DealVelocityReport`/`InventoryValuationReport`). Doar textul FIX al șablonului
    (numărul de rânduri, mesajul de listă goală) trece prin catalog aici (`lang/{en,fr}/pdf.php`).

    `<html lang>` citește `app()->getLocale()` direct, la fel ca `exports/pdf/list.blade.php`
    — motivul identic e documentat acolo (locale-ul e deja fixat de apelant, FR-I18N-05,
    cod din afara perimetrului acestui lot).
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page {
        margin: 16mm 12mm;
    }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9pt;
        color: #111111;
    }

    h1 {
        font-size: 14pt;
        margin: 0 0 2mm;
    }

    .meta {
        color: #555555;
        font-size: 8pt;
        margin-bottom: 5mm;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th,
    td {
        border: 0.5pt solid #cccccc;
        padding: 1.5mm 2.5mm;
        text-align: left;
        vertical-align: top;
    }

    th {
        background-color: #eeeeee;
        font-weight: bold;
    }

    td.numeric {
        text-align: right;
    }

    td.empty {
        text-align: center;
        color: #777777;
    }
</style>
</head>
<body>
    <h1>{{ $workspaceName }} — {{ $title }}</h1>
    <div class="meta">
        {{ __('pdf.meta.generated', ['date' => \App\Support\LocaleFormat::dateTime($generatedAt)]) }}
        {{-- `trans_choice()`, nu `Str::plural()` — vezi comentariul din
             exports/pdf/list.blade.php (capcana 0 = singular în franceză). --}}
        &middot; {{ trans_choice('pdf.meta.row_count', count($rows), ['count' => count($rows)]) }}
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td @class(['numeric' => is_int($cell) || is_float($cell)])>{{ $cell ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($headers) }}">{{ __('pdf.report.no_rows') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
