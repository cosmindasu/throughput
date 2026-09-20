<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * FR-BILL-04, specs.md §12.2 — un email pe FIECARE `invoice.payment_failed` primit cât
 * timp abonamentul e `past_due` (Stripe reîncearcă automat de mai multe ori; fiecare
 * încercare eșuată emite propriul eveniment, cu propriul `attempt_count`).
 *
 * `AttributesSentEmailToTenant` (aceeași motivare ca `ReportDeliveryMail`): fără trait-ul
 * ăsta, rândul din jurnalul „Sent Emails" ar avea `tenant_id = null`, invizibil în
 * Settings-ul tenantului care tocmai a avut o plată eșuată — exact tenantul pentru care
 * contează cel mai mult.
 *
 * NU implementează `ShouldQueue`: livrarea în coadă e deja asigurată de
 * `App\Listeners\Billing\SendPaymentFailedDunningEmail` (el însuși `ShouldQueue"),
 * simetric cu `ReportDeliveryMail`/`DeliverReportJob`.
 */
final class DunningPaymentFailedMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $workspaceSlug,
        public readonly int $attemptCount,
    ) {
        // Ultima linie, obligatoriu (docblock-ul trait-ului) — după ce toate
        // proprietățile scalare sunt atribuite.
        $this->attributeSentEmailToCurrentTenant();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment failed for your {$this->tenantName} subscription (attempt {$this->attemptCount})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'billing.mail.payment-failed');
    }
}
