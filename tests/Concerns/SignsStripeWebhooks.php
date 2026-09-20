<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;

/**
 * Construiește payload-uri de webhook Stripe SEMNATE LOCAL (§22.4 — niciun apel real către
 * Stripe, în niciun test), folosind `Stripe\WebhookSignature::generateSignatureHeader()`
 * din `stripe/stripe-php` (deja dependință a Cashier — „fără pachete noi").
 *
 * Fișier NOU, doar pentru testele acestui lot — ca `CreatesOrders`/`CreatesPipelines`, nu
 * atinge niciun fișier comun.
 */
trait SignsStripeWebhooks
{
    protected function stripeWebhookSecret(): string
    {
        return 'whsec_test_secret_for_pest';
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function stripeEvent(string $type, array $object, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.Str::random(24),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * Trimite payload-ul RAW (bytes identici cu cei semnați) — `$this->call()`, nu
     * `postJson()`: `postJson()` reface propriul `json_encode()`, fără garanția că
     * rezultatul e byte-identic cu ce a fost deja semnat mai sus.
     *
     * @param  array<string, mixed>  $event
     */
    protected function postStripeWebhook(array $event, ?string $signatureHeader = null): TestResponse
    {
        config(['cashier.webhook.secret' => $this->stripeWebhookSecret()]);

        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        $signatureHeader ??= WebhookSignature::generateSignatureHeader($payload, $this->stripeWebhookSecret());

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            $payload,
        );

        return $response;
    }
}
