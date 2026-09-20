<?php

namespace Tests\Feature\Webhooks;

use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * P2 securitate (review-ul lotului, pct. 4) — `POST /webhooks/stripe` e public, fără
 * `session.context`/`workspace`, deci fără nicio altă contenție înainte de acest lot.
 * `throttle:60,1` (routes/web.php) e cheiat pe IP implicit (fără utilizator autentificat).
 *
 * Fiecare cerere din acest test are un `event_id` UNIC, nemapat pe niciun tenant — cel mai
 * IEFTIN payload valid de semnat (semnătură verificată, idempotență verificată, niciun job
 * dispecerizat) — throttle-ul trebuie să limiteze INDIFERENT de conținut, deci nu are rost
 * un payload mai scump doar ca să-l testăm.
 */
class StripeWebhookRateLimitTest extends TestCase
{
    use SignsStripeWebhooks;

    public function test_the_sixty_first_request_in_a_minute_from_the_same_ip_is_throttled(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
                'id' => "sub_rate_limit_{$i}",
                'customer' => 'cus_does_not_exist_anywhere',
                'status' => 'active',
            ], eventId: "evt_rate_limit_{$i}"));

            $response->assertOk();
        }

        $blocked = $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_rate_limit_61',
            'customer' => 'cus_does_not_exist_anywhere',
            'status' => 'active',
        ], eventId: 'evt_rate_limit_61'));

        $blocked->assertStatus(429);
    }
}
