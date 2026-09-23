<?php

namespace Tests\Unit\Support\Sentry;

use App\Support\Sentry\ScrubSensitiveData;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/**
 * P3 securitate (review-ul lotului de abonament) — payload-urile `invoice.*` de la Stripe
 * cară `customer_email`; lista de chei redactate e a aplicației întregi, nu a unui lot.
 * Test unitar, fără bootstrap Laravel: clasa nu atinge nimic din framework.
 */
class ScrubSensitiveDataTest extends TestCase
{
    public function test_it_redacts_keys_containing_email_or_mail_at_any_depth(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'customer_email' => 'jane@example.com',
            'billing_details' => [
                'email' => 'jane@example.com',
                'mailing_address' => '123 Main St',
            ],
            'row_count' => 42,
        ]);

        $scrubbed = ScrubSensitiveData::handle($event);

        $extra = $scrubbed->getExtra();

        $this->assertSame('[redactat]', $extra['customer_email']);
        $this->assertSame('[redactat]', $extra['billing_details']['email']);
        $this->assertSame('[redactat]', $extra['billing_details']['mailing_address']);
        $this->assertSame(42, $extra['row_count']);
    }

    /**
     * SEC-AUD-01 (audit de securitate 2026-09-23) — mesajul unei excepții de furnizor poate
     * repeta o adresă de e-mail din cererea trimisă; nu are chei, deci filtrul pe chei nu-l vede.
     */
    public function test_it_redacts_email_addresses_from_exception_messages_and_the_event_message(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([
            new ExceptionDataBag(new RuntimeException('Invalid receipt_email: Jane.Doe+billing@example.co.uk (code 400)')),
        ]);
        $event->setMessage('Mail to ops@throughput.dev bounced');

        $scrubbed = ScrubSensitiveData::handle($event);

        $this->assertSame('Invalid receipt_email: [redactat] (code 400)', $scrubbed->getExceptions()[0]->getValue());
        $this->assertSame('Mail to [redactat] bounced', $scrubbed->getMessage());
    }
}
