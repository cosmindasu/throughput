<?php

namespace Tests\Unit\Support\Sentry;

use App\Support\Sentry\ScrubSensitiveData;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

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
}
