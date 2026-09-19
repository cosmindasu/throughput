{{-- Corpul emailului de livrare a unui raport (specs.md §16.2 pct. 4). Text simplu,
     deliberat — fără CSS de client de mail de întreținut pentru un singur mesaj. --}}
<p>Hi,</p>

<p>
    Your report "<strong>{{ $reportName }}</strong>" has been generated
    ({{ number_format($rowCount) }} {{ Str::plural('row', $rowCount) }}, {{ strtoupper($formatLabel) }}).
</p>

<p>The file is attached to this email.</p>

<p>— Throughput</p>
