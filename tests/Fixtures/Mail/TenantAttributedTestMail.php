<?php

namespace Tests\Fixtures\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Fixture pentru `App\Mail\Concerns\AttributesSentEmailToTenant` — un `Mailable` minimal
 * care CHEAMĂ CORECT `attributeSentEmailToCurrentTenant()`, ultima linie a constructorului.
 */
final class TenantAttributedTestMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(public readonly string $subjectLine)
    {
        $this->attributeSentEmailToCurrentTenant();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Body</p>');
    }
}
