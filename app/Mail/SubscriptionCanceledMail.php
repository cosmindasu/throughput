<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * specs.md §12.2/§20.5 — trimis „la tranziție" spre `canceled`
 * (`App\Support\Billing\Events\SubscriptionCanceled`), o singură dată (BR-BILL-05: aceeași
 * gardă care scrie `tenants.subscription_canceled_at`).
 */
final class SubscriptionCanceledMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $workspaceSlug,
        // ADR-022, specs.md §15.8 FR-I18N-05, Lot I18N Val 5 — CABLAT la apelantul real
        // (`App\Listeners\Billing\SendSubscriptionCanceledEmail`): un `Mailable` proaspăt
        // se construiește per Owner, iar `Mail::to($owner)` (modelul, nu adresa) declanșează
        // `Illuminate\Mail\PendingMail::to()` să citească `$owner->preferredLocale()` și să
        // suprascrie acest parametru înainte de trimitere (`PendingMail::fill()`). Rămâne
        // `null` aici — locale-ul REAL vine mereu din `Mail::to()`, nu din constructor.
        ?string $locale = null,
    ) {
        $this->attributeSentEmailToCurrentTenant();
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('mail.subscription_canceled.subject', ['tenant' => $this->tenantName], $this->locale),
        );
    }

    public function content(): Content
    {
        // A11Y-07 — `$this->subject` e deja hidratat din `envelope()` la acest punct
        // (`Illuminate\Mail\Mailable::ensureEnvelopeIsHydrated()` rulează înaintea lui
        // `ensureContentIsHydrated()`); vederea îl folosește doar pentru `<title>`, prin
        // layout-ul comun `resources/views/mail/layout.blade.php`.
        return new Content(view: 'billing.mail.canceled', with: ['subject' => $this->subject]);
    }
}
