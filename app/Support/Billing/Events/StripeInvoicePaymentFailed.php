<?php

namespace App\Support\Billing\Events;

/**
 * FR-BILL-04 — declanșat de `App\Jobs\Webhooks\ProcessStripeWebhookJob` la fiecare
 * `invoice.payment_failed` (nu doar la prima încercare: Stripe retrimite acest eveniment
 * la fiecare reîncercare eșuată cât timp abonamentul e `past_due`, iar Owner-ul primește
 * un email la fiecare, cu `attemptCount` — specs.md §12.2, tabelul de degradare).
 *
 * Doar scalari (§6.3) — evenimentul traversează bus-ul de coadă via listener-ul
 * `ShouldQueue`, deci nu poate ține un model Eloquent serializat.
 */
final class StripeInvoicePaymentFailed
{
    public function __construct(
        public readonly string $tenantId,
        public readonly int $attemptCount,
        public readonly string $webhookEventId,
    ) {}
}
