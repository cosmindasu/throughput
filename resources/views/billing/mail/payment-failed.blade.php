{{-- Corpul emailului de dunning (specs.md §12.2, FR-BILL-04). Text simplu, ca la
     reports.mail.delivery — un singur mesaj nu justifică CSS de client de mail. --}}
<p>Hi,</p>

<p>
    A payment attempt for the <strong>{{ $tenantName }}</strong> subscription failed
    (attempt {{ $attemptCount }}). Stripe will keep retrying automatically — your
    workspace still has full access while this happens.
</p>

<p>
    To avoid any interruption, update the payment method from the billing page:
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>— Throughput</p>
