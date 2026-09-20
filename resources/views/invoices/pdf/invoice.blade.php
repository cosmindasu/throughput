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
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->invoice_number }}</title>
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
                <h1>{{ $invoice->tenant?->name ?? 'Invoice' }}</h1>
                <div class="muted">Invoice {{ $invoice->invoice_number }}</div>
            </td>
            <td class="right">
                <span class="status">{{ strtoupper($invoice->status) }}</span>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="label">Bill to</td>
            <td>
                {{ $invoice->order?->account?->name ?? '—' }}
                @if ($invoice->order?->contact)
                    <br>
                    <span class="muted">
                        {{ trim(($invoice->order->contact->first_name ?? '').' '.($invoice->order->contact->last_name ?? '')) ?: 'Anonymized contact' }}
                    </span>
                @endif
            </td>
            <td class="label">Issue date</td>
            <td>{{ $invoice->issue_date?->toFormattedDateString() ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Order</td>
            <td>{{ $invoice->order?->order_number ?? '—' }}</td>
            <td class="label">Due date</td>
            <td>{{ $invoice->due_date?->toFormattedDateString() ?? '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Line</th>
                <th class="numeric">Quantity</th>
                <th class="numeric">Unit price</th>
                <th class="numeric">Discount</th>
                <th class="numeric">Line total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoice->order?->orderLines ?? [] as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="numeric">{{ $line->quantity }}</td>
                    <td class="numeric">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="numeric">{{ number_format((float) $line->discount, 2) }}</td>
                    <td class="numeric">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: #777777;">No lines on the source order.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Tax</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->tax_total, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Paid</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->amount_paid, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Balance due</td>
            <td class="value">{{ $invoice->currency }} {{ number_format((float) $invoice->balance_due, 2) }}</td>
        </tr>
    </table>
</body>
</html>
