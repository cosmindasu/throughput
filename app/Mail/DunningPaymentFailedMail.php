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
        // ADR-022, specs.md §15.8 FR-I18N-05, Lot I18N Val 5 — CABLAT la apelantul real
        // (`App\Listeners\Billing\SendPaymentFailedDunningEmail`): un `Mailable` proaspăt
        // se construiește per Owner, iar `Mail::to($owner)` (modelul, nu adresa) declanșează
        // `Illuminate\Mail\PendingMail::to()` să citească `$owner->preferredLocale()` și să
        // suprascrie acest parametru înainte de trimitere (`PendingMail::fill()`). Rămâne
        // `null` aici — locale-ul REAL vine mereu din `Mail::to()`, nu din constructor.
        ?string $locale = null,
    ) {
        // Ultima linie, obligatoriu (docblock-ul trait-ului) — după ce toate
        // proprietățile scalare sunt atribuite.
        $this->attributeSentEmailToCurrentTenant();
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('mail.dunning_payment_failed.subject', [
                'tenant' => $this->tenantName,
                'attempt' => $this->attemptCount,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        // A11Y-07 — vezi nota din `SubscriptionCanceledMail::content()`.
        return new Content(view: 'billing.mail.payment-failed', with: ['subject' => $this->subject]);
    }
}
