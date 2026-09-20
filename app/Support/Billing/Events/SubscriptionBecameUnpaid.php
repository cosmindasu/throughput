<?php

namespace App\Support\Billing\Events;

/**
 * specs.md §12.2, tabelul de degradare — rândul `unpaid`, coloana „Email către Owner":
 * „O SINGURĂ DATĂ, la tranziția past_due → unpaid". Declanșat de
 * `App\Jobs\Webhooks\ProcessStripeWebhookJob::syncSubscription()` DOAR când statusul local
 * chiar TRANZIȚIONEAZĂ spre `unpaid` (comparat cu valoarea dinaintea acestei scrieri) —
 * nu la fiecare webhook care confirmă un `unpaid` deja cunoscut. Aceeași disciplină de
 * idempotență ca `BR-BILL-05`/`subscription_canceled_at`, nu un mecanism nou.
 */
final class SubscriptionBecameUnpaid
{
    public function __construct(
        public readonly string $tenantId,
    ) {}
}
