{{--
    Șablon PDF de export listă (§13.5, Orders — `App\Support\Exports\PdfExporter`).

    CSS 2.1 doar (fără flex/grid — DomPDF nu le înțelege), fără resurse remote
    (`laravel-pdf.dompdf.is_remote_enabled` rămâne `false`), font `DejaVu Sans` (diacritice,
    unul din fonturile compilate cu DomPDF). Aceleași coloane fixe ca CSV-ul
    (`$headers`/`$rows`, din `ExportableList::exportHeaders()`/`exportRow()`), cifrele
    aliniate la dreapta.

    `<html lang>` citește `app()->getLocale()` direct, NU o variabilă pasată de apelant:
    `PdfExporter::save()` (afara perimetrului, dincolo de headerele de coloană) rulează
    mereu în coada `ExportListJob`, care trebuie să fi fixat deja `App::setLocale()` la
    limba destinatarului (FR-I18N-05) înainte de randare — exact locale-ul pe care
    `trans()`/`trans_choice()` de mai jos îl folosesc oricum. O variabilă separată ar fi o a
    doua sursă de adevăr pentru aceeași valoare.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('pdf.export.title') }}</title>
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
    <h1>{{ $workspaceName }} — {{ __('pdf.export.heading_suffix') }}</h1>
    <div class="meta">
        {{ __('pdf.meta.generated', ['date' => \App\Support\LocaleFormat::dateTime($generatedAt)]) }}
        @if ($filters !== [])
            {{-- Cheile filtrului ($key) vin din `PdfExporter::humanizeFilters()`, în afara
                 perimetrului acestui lot (nu e header de coloană) — rămân engleze. --}}
            &middot; {{ __('pdf.export.filters_label') }}:
            @foreach ($filters as $key => $value)
                {{ $key }}: {{ $value }}@if (! $loop->last), @endif
            @endforeach
        @endif
        {{-- CAPCANA CENTRALĂ (raportul lotului): `Str::plural()` aplică regula engleză —
             0 tratat ca plural. Franceza tratează 0 CA SINGULAR. `trans_choice()` alege
             segmentul corect per `App::getLocale()` (vezi comentariul din lang/en/pdf.php),
             deci un singur catalog cu două segmente ajunge pentru ambele limbi. --}}
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
                    <td class="empty" colspan="{{ count($headers) }}">{{ __('pdf.export.no_rows') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
