{{-- specs.md §12.2/§20.5 — trimis la tranziția spre canceled. --}}
<p>Hi,</p>

<p>
    The <strong>{{ $tenantName }}</strong> subscription was canceled. The workspace is now
    locked for everyone except the billing page — data stays intact and exportable for 30
    days, and you can reactivate at any time during that window without redoing setup.
</p>

<p>
    Reactivate from the billing page:
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>— Throughput</p>
