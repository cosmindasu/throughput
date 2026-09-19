{{--
    Șablon PDF de export listă (§13.5, Orders — `App\Support\Exports\PdfExporter`).

    CSS 2.1 doar (fără flex/grid — DomPDF nu le înțelege), fără resurse remote
    (`laravel-pdf.dompdf.is_remote_enabled` rămâne `false`), font `DejaVu Sans` (diacritice,
    unul din fonturile compilate cu DomPDF). Aceleași coloane fixe ca CSV-ul
    (`$headers`/`$rows`, din `ExportableList::exportHeaders()`/`exportRow()`), cifrele
    aliniate la dreapta.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Export</title>
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
    <h1>{{ $workspaceName }} — export</h1>
    <div class="meta">
        Generated {{ $generatedAt->toDayDateTimeString() }}
        @if ($filters !== [])
            &middot; Filters:
            @foreach ($filters as $key => $value)
                {{ $key }}: {{ $value }}@if (! $loop->last), @endif
            @endforeach
        @endif
        &middot; {{ count($rows) }} {{ Str::plural('row', count($rows)) }}
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
                    <td class="empty" colspan="{{ count($headers) }}">No rows match this filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
