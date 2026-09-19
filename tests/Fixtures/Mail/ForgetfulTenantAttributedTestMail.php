<?php

namespace Tests\Fixtures\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Fixture pentru `App\Mail\Concerns\AttributesSentEmailToTenant` — un `Mailable` care
 * folosește trait-ul dar UITĂ să cheme `attributeSentEmailToCurrentTenant()`, exact
 * scenariul pe care garda din `headers()` trebuie s-o raporteze (nu s-o blocheze).
 */
final class ForgetfulTenantAttributedTestMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Forgetful mailable');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Body</p>');
    }
}
