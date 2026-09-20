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
    ) {
        $this->attributeSentEmailToCurrentTenant();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->tenantName} subscription canceled",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'billing.mail.canceled');
    }
}
