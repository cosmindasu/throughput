{{-- Corpul notificării de export GDPR (FR-GDPR-01, specs.md §20.5). Text simplu, ca la
     `reports/mail/delivery.blade.php` — fără CSS de client de mail de întreținut. --}}
<p>Hi {{ $requestedByName }},</p>

<p>
    The data export you requested for <strong>{{ $workspaceName }}</strong> is ready to download.
</p>

<p>
    <a href="{{ $downloadUrl }}">Download the archive</a>
</p>

<p>
    The link works for {{ $retentionDays }} {{ Str::plural('day', $retentionDays) }} and stops working on
    {{ $expiresOn }}, after which the file is deleted. The request itself stays in the export history,
    so there is always a record that it was made — you can request a new export at any time.
</p>

<p>
    The archive holds one JSON file per entity, a CSV alongside it wherever the table is flat, and a
    manifest describing what is inside and what is not.
</p>

<p>— Throughput</p>
