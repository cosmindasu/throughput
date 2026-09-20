{{-- specs.md §12.2 — o singură dată, la tranziția past_due → unpaid. --}}
<p>Hi,</p>

<p>
    Stripe has exhausted its automatic retries for the <strong>{{ $tenantName }}</strong>
    subscription, and it is now marked <strong>unpaid</strong>. Everyone in the workspace can
    still view and export data, but creating, editing or deleting anything is blocked until
    the payment method is fixed — including for you, the Owner.
</p>

<p>
    Update the payment method from the billing page:
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>— Throughput</p>
