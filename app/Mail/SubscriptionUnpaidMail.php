<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * specs.md §12.2 — trimis O SINGURĂ DATĂ, la tranziția `past_due → unpaid`
 * (`App\Support\Billing\Events\SubscriptionBecameUnpaid`). US-BILL-04: Owner-ul trebuie să
 * afle ÎNAINTE să deschidă aplicația și să găsească scrisul blocat, nu după.
 *
 * `AttributesSentEmailToTenant` — aceeași motivare ca `DunningPaymentFailedMail`.
 */
final class SubscriptionUnpaidMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $workspaceSlug,
        // ADR-022, specs.md §15.8 FR-I18N-05 — aceeași notă ca `DunningPaymentFailedMail`:
        // destinatarii sunt un ARRAY de Owner-i, nerezolvat automat de `Mail::to()`.
        // NEcablat încă la apelantul real
        // (`App\Listeners\Billing\SendSubscriptionUnpaidEmail`, în afara perimetrului
        // acestui lot). `null` păstrează comportamentul actual.
        ?string $locale = null,
    ) {
        $this->attributeSentEmailToCurrentTenant();
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('mail.subscription_unpaid.subject', ['tenant' => $this->tenantName], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'billing.mail.unpaid');
    }
}
