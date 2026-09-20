{{-- Corpul emailului de livrare a unui raport (specs.md §16.2 pct. 4). Text simplu,
     deliberat — fără CSS de client de mail de întreținut pentru un singur mesaj.

     ADR-022, specs.md §15.8 FR-I18N-04/05 — randat în limba DESTINATARULUI (creatorul
     raportului, FR-I18N-05): `App\Jobs\Reports\DeliverReportJob` cheamă `->locale(...)`
     pe `App\Mail\ReportDeliveryMail` înainte de trimitere, ceea ce înfășoară randarea
     acestei vederi în `Illuminate\Support\Traits\Localizable::withLocale()` — locale-ul
     corect e deja activ când Blade ajunge aici, fără nimic suplimentar de făcut în
     această vedere.

     `<strong>` din jurul numelui raportului se compune manual, cu `e()`, ÎNAINTE de
     interpolarea în `__()` — `$reportName` e conținut introdus de UTILIZATOR
     (`report_definitions.name`, FR-I18N-06: NU se traduce), iar rezultatul se randează
     apoi RAW (`{!! !!}`), ca traducerea să poată păstra tag-ul fără să redeschidă o
     gaură XSS pe un nume de raport ostil ("<script>..."). --}}
<p>{{ __('mail.report_delivery.greeting') }}</p>

<p>
    {!! __('mail.report_delivery.body', [
        'report' => '<strong>'.e($reportName).'</strong>',
        'rows' => trans_choice('mail.report_delivery.rows', $rowCount, ['count' => number_format($rowCount)]),
        'format' => strtoupper($formatLabel),
    ]) !!}
</p>

<p>{{ __('mail.report_delivery.attached') }}</p>

<p>{{ __('mail.report_delivery.signature') }}</p>
