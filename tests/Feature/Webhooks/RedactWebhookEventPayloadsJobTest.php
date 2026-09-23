<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\System\RedactWebhookEventPayloadsJob;
use App\Models\WebhookEvent;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * GDPR-03 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`, P2) —
 * `RedactWebhookEventPayloadsJob`: retenția payload-ului Stripe complet stocat în
 * `webhook_events.payload` (`customer_email` inclus — vezi docblock-ul jobului).
 *
 * Pragul jobului e 90 de zile (constantă de clasă, `RedactWebhookEventPayloadsJob::
 * RETENTION_DAYS`) — fixturile de aici folosesc 91/89 zile, DELIBERAT departe de graniță
 * (nu 90/90), ca testul să nu depindă de rotunjirea exactă a comparației `<`.
 */
class RedactWebhookEventPayloadsJobTest extends TestCase
{
    use SignsStripeWebhooks;

    public function test_a_processed_event_older_than_the_threshold_gets_its_payload_redacted(): void
    {
        $event = $this->recordEvent('evt_old_processed', WebhookEvent::STATUS_PROCESSED, now()->subDays(91));

        (new RedactWebhookEventPayloadsJob)->handle();

        $redacted = WebhookEvent::query()->findOrFail($event->id);

        $this->assertEquals(
            ['redacted' => true, 'type' => 'customer.subscription.updated', 'id' => 'evt_old_processed'],
            $redacted->payload,
        );

        // `payload_hash` rămâne dovada hash-ului ORIGINAL primit de la Stripe — nu se
        // recalculează la redactare (docblock-ul jobului).
        $this->assertSame($event->payload_hash, $redacted->payload_hash);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $redacted->status);
    }

    public function test_an_ignored_event_older_than_the_threshold_gets_redacted_too(): void
    {
        $event = $this->recordEvent('evt_old_ignored', WebhookEvent::STATUS_IGNORED, now()->subDays(91));

        (new RedactWebhookEventPayloadsJob)->handle();

        $redacted = WebhookEvent::query()->findOrFail($event->id);

        $this->assertEquals(
            ['redacted' => true, 'type' => 'customer.subscription.updated', 'id' => 'evt_old_ignored'],
            $redacted->payload,
        );
    }

    public function test_a_processed_event_within_the_threshold_is_left_intact(): void
    {
        $event = $this->recordEvent('evt_recent_processed', WebhookEvent::STATUS_PROCESSED, now()->subDays(89));

        (new RedactWebhookEventPayloadsJob)->handle();

        $untouched = WebhookEvent::query()->findOrFail($event->id);

        $this->assertEquals($event->payload, $untouched->payload);
    }

    /**
     * `failed` e terminal ÎN COD (a treia încercare eșuată), dar rămâne exclus DELIBERAT
     * din redactare (docblock-ul jobului) — un operator ar putea investiga manual, redeschizând
     * nevoia de payload-ul original.
     */
    public function test_a_failed_event_is_never_redacted_regardless_of_age(): void
    {
        $event = $this->recordEvent('evt_old_failed', WebhookEvent::STATUS_FAILED, now()->subDays(365));

        (new RedactWebhookEventPayloadsJob)->handle();

        $untouched = WebhookEvent::query()->findOrFail($event->id);

        $this->assertEquals($event->payload, $untouched->payload);
    }

    /**
     * `received`/`processing` sunt stări care mai pot fi REÎNCERCATE — redactarea le-ar
     * priva de datele de care o reîncercare are nevoie. Testat aici cu `received`, cea mai
     * timpurie stare posibilă (un rând care ar fi rămas blocat acolo).
     */
    public function test_a_received_event_is_never_redacted_regardless_of_age(): void
    {
        $event = $this->recordEvent('evt_stuck_received', WebhookEvent::STATUS_RECEIVED, now()->subDays(365));

        (new RedactWebhookEventPayloadsJob)->handle();

        $untouched = WebhookEvent::query()->findOrFail($event->id);

        $this->assertEquals($event->payload, $untouched->payload);
    }

    /**
     * Rulare a doua oară pe un rând deja redactat — convergență, nu doar corectitudine la
     * prima trecere (docblock-ul jobului: „idempotent prin convergență").
     */
    public function test_running_the_job_twice_on_an_already_redacted_row_is_a_no_op(): void
    {
        $event = $this->recordEvent('evt_double_run', WebhookEvent::STATUS_PROCESSED, now()->subDays(91));

        (new RedactWebhookEventPayloadsJob)->handle();
        $firstPass = WebhookEvent::query()->findOrFail($event->id)->payload;

        (new RedactWebhookEventPayloadsJob)->handle();
        $secondPass = WebhookEvent::query()->findOrFail($event->id)->payload;

        $this->assertSame($firstPass, $secondPass);
    }

    /**
     * Dedup-ul din `StripeWebhookController` se face pe `(source, event_id)`
     * (`WebhookEvent::firstOrCreate()`), nu pe conținutul lui `payload` — redactarea nu
     * trebuie să deschidă o portiță pentru reprocesare la o redelivrare Stripe a aceluiași
     * `event_id`. Rulare END-TO-END, ca `StripeWebhookIdempotencyTest`.
     */
    public function test_deduplication_by_event_id_still_works_after_redaction(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);
        $tenant->forceFill(['stripe_id' => 'cus_marlin_redaction_test'])->save();
        $this->clearDatabaseTenantContext();

        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_redaction_test',
            'customer' => 'cus_marlin_redaction_test',
            'status' => 'active',
        ], eventId: 'evt_redaction_dedup');

        $this->postStripeWebhook($event)->assertOk();
        $this->assertSame(1, WebhookEvent::query()->count());

        // Preluat de a treia încercare eșuată sau succes — indiferent, doar terminal:
        // marcat manual `processed` + vechi, ca redactarea să aibă ce prinde fără să
        // depindă de a lucra coada.
        WebhookEvent::query()->where('event_id', 'evt_redaction_dedup')->update([
            'status' => WebhookEvent::STATUS_PROCESSED,
            'received_at' => now()->subDays(91),
        ]);
        DB::table('jobs')->delete();

        (new RedactWebhookEventPayloadsJob)->handle();

        $redacted = WebhookEvent::query()->where('event_id', 'evt_redaction_dedup')->sole();
        $this->assertTrue(($redacted->payload)['redacted'] ?? false);

        // Stripe redelivers the SAME event_id — the payload no longer carries the original
        // content, but the row still exists, so the controller must still recognize it.
        $second = $this->postStripeWebhook($event);
        $second->assertOk();

        $this->assertSame(
            1,
            WebhookEvent::query()->where('event_id', 'evt_redaction_dedup')->count(),
            'A redacted row must still deduplicate — no second row for the same event_id.',
        );

        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'A redelivered, already-recorded event must not dispatch a new processing job.',
        );
    }

    /**
     * `WebhookHealthController` nu citește niciodată `payload` (docblock-ul controllerului)
     * — ecranul trebuie să rămână identic după redactare.
     */
    public function test_the_webhook_health_screen_still_renders_after_redaction(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->recordEvent('evt_health_old_processed', WebhookEvent::STATUS_PROCESSED, now()->subDays(91));
        $this->recordEvent('evt_health_recent_failed', WebhookEvent::STATUS_FAILED, now()->subDays(1));

        (new RedactWebhookEventPayloadsJob)->handle();

        $this->actingAs($owner)
            ->get('/marlin/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/WebhookHealth/Index')
                ->where('counts.processed', 1)
                ->where('counts.failed', 1)
                ->has('events', 2)
                ->missing('events.0.payload')
                ->missing('events.1.payload')
            );
    }

    private function recordEvent(string $eventId, string $status, Carbon $receivedAt): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'source' => WebhookEvent::SOURCE_STRIPE,
            'event_id' => $eventId,
            'type' => 'customer.subscription.updated',
            'payload' => ['id' => $eventId, 'type' => 'customer.subscription.updated', 'data' => ['object' => ['customer' => 'cus_x']]],
            'payload_hash' => hash('sha256', $eventId),
            'status' => $status,
            'received_at' => $receivedAt,
        ]);
    }
}
