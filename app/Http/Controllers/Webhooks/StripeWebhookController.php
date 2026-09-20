<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Webhooks\ProcessStripeWebhookJob;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

/**
 * `POST /webhooks/stripe` — specs.md §12.3. Rută PUBLICĂ, în afara grupului cu workspace
 * (`routes/web.php`): Stripe n-are sesiune, n-are workspace, n-are token CSRF Laravel
 * (excepție explicită în `bootstrap/app.php`). ADR-014 pct. 4: „ruta e publică și fără
 * context — tenants nu are RLS, deci lookup-ul după stripe_id funcționează."
 *
 * NU extinde `Laravel\Cashier\Http\Controllers\WebhookController`: acel controller are
 * handler-e care fac apeluri SINCRONE către Stripe (`updateDefaultPaymentMethodFromStripe()`
 * pe `customer.updated`/`payment_method.automatically_updated`) — exact ce ADR-013
 * interzice în interiorul cererii HTTP. Semnătura se verifică aici (rapid, local, fără
 * rețea), efectul se aplică într-un job (`ProcessStripeWebhookJob`).
 *
 * Flux (§12.3, pct. 1-5):
 *   1. Semnătură invalidă → 400 imediat, NIMIC persistat (nu știm încă dacă payload-ul
 *      e de încredere, deci nu-l scriem).
 *   2. `(source, event_id)` deja cunoscut → 200 imediat, FĂRĂ reprocesare — indiferent de
 *      `status`-ul curent al acelui rând (`received`/`processing`/`processed`/`failed`
 *      — vezi criteriul de acceptanță literal din specs.md §12.3, pct. 2).
 *   3. Rând nou + `stripe_id` nemapat pe niciun tenant → `status = ignored`, motiv
 *      explicit, 200 (nu o excepție necontrolată — ADR-014 pct. 4). Era `failed` până la
 *      2026-09-20 — vezi `WebhookEvent::STATUS_IGNORED` și docblock-ul lui `ignore()`.
 *   4. Rând nou + tenant găsit → dispatch `ProcessStripeWebhookJob`, 200.
 *
 * `WebhookEvent::firstOrCreate()` e RACE-SAFE în Laravel 13 (`Builder::createOrFirst()`
 * prinde `UniqueConstraintViolationException` și re-interoghează) — două livrări
 * SIMULTANE ale aceluiași `event_id` (retry de rețea Stripe) nu produc o eroare 500 pe
 * una din ele, ambele ajung la același rând, doar una are `wasRecentlyCreated === true`.
 */
final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();

        try {
            WebhookSignature::verifyHeader(
                $payload,
                (string) $request->header('Stripe-Signature'),
                (string) config('cashier.webhook.secret'),
                (int) config('cashier.webhook.tolerance', 300),
            );
        } catch (SignatureVerificationException) {
            return response('Invalid Stripe-Signature header.', 400);
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['type'])) {
            return response('Malformed event payload.', 400);
        }

        $event = WebhookEvent::query()->firstOrCreate(
            ['source' => WebhookEvent::SOURCE_STRIPE, 'event_id' => $decoded['id']],
            [
                'type' => $decoded['type'],
                'payload' => $decoded,
                'payload_hash' => hash('sha256', $payload),
                'status' => WebhookEvent::STATUS_RECEIVED,
                'received_at' => now(),
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return response('Event already recorded.', 200);
        }

        $customerId = $decoded['data']['object']['customer'] ?? null;
        $tenant = $customerId !== null ? Tenant::query()->where('stripe_id', $customerId)->first() : null;

        if ($tenant === null) {
            return $this->ignore($event, $customerId);
        }

        // Coada implicită (`default`) — `config/horizon.php` are UN singur supervisor,
        // cu o listă fixă de cozi (buget de memorie §3); o coadă nouă „webhooks" ar
        // rămâne needeservită fără o a doua editare, în afara fișierelor acestui lot.
        ProcessStripeWebhookJob::dispatch($tenant->getKey(), $event->getKey());

        return response('OK', 200);
    }

    /**
     * Pct. 3 al fluxului — semnătură VALIDĂ, eveniment care nu ne privește.
     *
     * Decizie operațională a proprietarului (2026-09-20): sandbox-ul Stripe rămâne împărțit
     * cu alt proiect, deci acest endpoint primește, legitim și semnat corect, evenimentele
     * aceluia. `failed` descria greșit situația — nimic n-a eșuat la noi — și ar fi umplut
     * ecranul de operare (§25.2) cu roșu străin, până când roșul nu mai înseamnă nimic.
     *
     * `error_message` EXPLICĂ, nu acuză: spune ce lipsește (maparea) și de ce e normal
     * (sandbox partajat), ca operatorul să nu caute o defecțiune inexistentă. Rămâne
     * populat, nu `null` — e singura coloană pe care ecranul de operare o poate arăta ca
     * motiv, iar un rând `ignored` fără explicație ar fi doar o altă formă de mister.
     *
     * Răspunsul rămâne 200, din același motiv ca înainte (ADR-014 pct. 4): Stripe n-are
     * ce reîncerca. NICIUN job nu se dispecerizează — nu există tenant în al cărui context
     * să ruleze.
     */
    private function ignore(WebhookEvent $event, ?string $customerId): Response
    {
        $event->update([
            'status' => WebhookEvent::STATUS_IGNORED,
            'error_message' => $customerId === null
                ? 'Not for this deployment: the event has no data.object.customer, so there is no workspace it could belong to.'
                : "Not for this deployment: Stripe customer {$customerId} does not belong to any workspace here. The Stripe sandbox is shared with another project, so its events arrive at this endpoint too.",
        ]);

        return response('OK', 200);
    }
}
