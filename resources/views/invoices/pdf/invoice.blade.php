{{--
    Șablon PDF de factură (specs.md §12.1, US-BILL-01) — `App\Jobs\Invoices\GenerateInvoicePdfJob`.

    Mirror deliberat, minimal, al `resources/views/exports/pdf/list.blade.php` și
    `resources/views/reports/pdf/built-in.blade.php` (§13.5/§16.3): NU e o editare a acelor
    fișiere (fișiere ale altor loturi), ci un șablon propriu, cu propriul layout (o factură,
    nu un tabel de export). Aceleași convenții DomPDF, decizie explicită a proprietarului
    (2026-09-19): CSS 2.1 (fără flex/grid), fără resurse remote, font „DejaVu Sans" pentru
    diacritice.

    `$invoice` vine cu `order.account`, `order.contact`, `order.orderLines.variant.product`
    și `tenant` deja eager-load-uite de job — niciun acces la altă relație aici, ca să nu
    declanșeze o interogare lazy în afara contextului de tenant (vezi docblock-ul jobului).

    I18N (FR-I18N-04, ADR-022, lot dedicat) — textul fix trece prin catalog
    (`lang/{en,fr}/pdf.php`, namespace `pdf.invoice.*`); traducerea de facturare e
    terminologie specializată, semnalată explicit „⚠ NATIV" în `lang/fr/pdf.php` pentru
    revizia proprietarului. Starea facturii (`pdf.invoice.status.*`) oglindește EXACT cele
    5 valori `Invoice::STATUS_*` (coloană enum în schemă) — nicio a șasea valoare posibilă.

    `<html lang>` citește `app()->getLocale()` direct — motivul identic celor două șabloane
    mirror (`exports/pdf/list.blade.php`, `reports/pdf/built-in.blade.php`): locale-ul e deja
    fixat de `GenerateInvoicePdfJob` (FR-I18N-05, cod din afara perimetrului acestui lot)
    înainte de randare.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ __('pdf.invoice.title', ['number' => $invoice->invoice_number]) }}</title>
<style>
    @page {
        margin: 18mm 16mm;
    }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 10pt;
        color: #111111;
    }

    h1 {
        font-size: 18pt;
        margin: 0 0 1mm;
    }

    .muted {
        color: #555555;
    }

    .header {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8mm;
    }

    .header td {
        vertical-align: top;
        padding: 0;
    }

    .header td.right {
        text-align: right;
    }

    .meta-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8mm;
    }

    .meta-table td {
        padding: 1mm 0;
        vertical-align: top;
    }

    .meta-table td.label {
        color: #555555;
        width: 35mm;
    }

    table.lines {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6mm;
    }

    table.lines th,
    table.lines td {
        border: 0.5pt solid #cccccc;
        padding: 2mm 2.5mm;
        text-align: left;
        vertical-align: top;
    }

    table.lines th {
        background-color: #eeeeee;
        font-weight: bold;
    }

    td.numeric,
    th.numeric {
        text-align: right;
    }

    table.totals {
        width: 60mm;
        margin-left: auto;
        border-collapse: collapse;
    }

    table.totals td {
        padding: 1mm 0;
    }

    table.totals td.label {
        color: #555555;
    }

    table.totals td.value {
        text-align: right;
    }

    table.totals tr.grand td {
        border-top: 0.5pt solid #333333;
        font-weight: bold;
        padding-top: 2mm;
    }

    .status {
        display: inline-block;
        padding: 1mm 3mm;
        border: 0.5pt solid #333333;
        text-transform: uppercase;
        font-size: 8pt;
    }
</style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ $invoice->tenant?->name ?? __('pdf.invoice.fallback_tenant_name') }}</h1>
                <div class="muted">{{ __('pdf.invoice.title', ['number' => $invoice->invoice_number]) }}</div>
            </td>
            <td class="right">
                {{-- Cheile oglindesc exact `Invoice::STATUS_*` (coloană enum în schemă) —
                     nicio a șasea valoare posibilă, deci fără fallback defensiv aici. --}}
                <span class="status">{{ Str::upper(__('pdf.invoice.status.'.$invoice->status)) }}</span>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="label">{{ __('pdf.invoice.bill_to') }}</td>
            <td>
                {{ $invoice->order?->account?->name ?? '—' }}
                @if ($invoice->order?->contact)
                    <br>
                    <span class="muted">
                        {{ trim(($invoice->order->contact->first_name ?? '').' '.($invoice->order->contact->last_name ?? '')) ?: __('pdf.invoice.anonymized_contact') }}
                    </span>
                @endif
            </td>
            <td class="label">{{ __('pdf.invoice.issue_date') }}</td>
            <td>{{ \App\Support\LocaleFormat::date($invoice->issue_date) ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('pdf.invoice.order') }}</td>
            <td>{{ $invoice->order?->order_number ?? '—' }}</td>
            <td class="label">{{ __('pdf.invoice.due_date') }}</td>
            <td>{{ \App\Support\LocaleFormat::date($invoice->due_date) ?? '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('pdf.invoice.line') }}</th>
                <th class="numeric">{{ __('pdf.invoice.quantity') }}</th>
                <th class="numeric">{{ __('pdf.invoice.unit_price') }}</th>
                <th class="numeric">{{ __('pdf.invoice.discount') }}</th>
                <th class="numeric">{{ __('pdf.invoice.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->order?->orderLines ?? [] as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="numeric">{{ $line->quantity }}</td>
                    <td class="numeric">{{ \App\Support\LocaleFormat::amount((float) $line->unit_price) }}</td>
                    <td class="numeric">{{ \App\Support\LocaleFormat::amount((float) $line->discount) }}</td>
                    <td class="numeric">{{ \App\Support\LocaleFormat::amount((float) $line->line_total) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #777777;">{{ __('pdf.invoice.no_lines') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">{{ __('pdf.invoice.subtotal') }}</td>
            <td class="value">{{ \App\Support\LocaleFormat::money((float) $invoice->subtotal, $invoice->currency) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('pdf.invoice.tax') }}</td>
            <td class="value">{{ \App\Support\LocaleFormat::money((float) $invoice->tax_total, $invoice->currency) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">{{ __('pdf.invoice.total') }}</td>
            <td class="value">{{ \App\Support\LocaleFormat::money((float) $invoice->total, $invoice->currency) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('pdf.invoice.paid') }}</td>
            <td class="value">{{ \App\Support\LocaleFormat::money((float) $invoice->amount_paid, $invoice->currency) }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('pdf.invoice.balance_due') }}</td>
            <td class="value">{{ \App\Support\LocaleFormat::money((float) $invoice->balance_due, $invoice->currency) }}</td>
        </tr>
    </table>
</body>
</html>
