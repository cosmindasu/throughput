<?php

namespace Tests\Feature\Webhooks;

use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * specs.md §12.3 — „Given un webhook cu un event_id deja procesat cu succes, When e
 * retrimis de Stripe (simulare test), Then aplicația răspunde 200 fără a modifica starea
 * abonamentului a doua oară." Test P0 al lotului (plan §11: „Test dedicat: retrimite
 * același event_id... → efect aplicat o singură dată").
 *
 * Coada `database` (`.ai/rules/tenancy.md`) — jobul chiar rulează, cu contextul lui
 * propriu, nu inline (`Queue::fake()` ar ascunde exact bug-ul de serializare pe care
 * regula asta există să-l prindă).
 */
class StripeWebhookIdempotencyTest extends TestCase
{
    use SignsStripeWebhooks;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->tenant->forceFill(['stripe_id' => 'cus_marlin_test'])->save();

        $this->clearDatabaseTenantContext();
    }

    public function test_a_repeated_event_id_is_applied_only_once(): void
    {
        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'past_due',
            'items' => ['data' => [
                ['id' => 'si_1', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1],
            ]],
        ], eventId: 'evt_repeat_test_1');

        $first = $this->postStripeWebhook($event);
        $first->assertOk();

        $this->assertSame(1, WebhookEvent::query()->count());

        $this->workTheQueue(1);

        $this->assertSame(
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::query()->sole()->status,
        );

        $this->assertSame(
            'past_due',
            Subscription::query()->where('user_id', $this->tenant->getKey())->value('stripe_status'),
        );

        // Stripe redelivers the SAME event (network retry, timeout) — identical id AND
        // signature. The literal acceptance criterion: 200, no reprocessing.
        $second = $this->postStripeWebhook($event);
        $second->assertOk();

        $this->assertSame(
            1,
            WebhookEvent::query()->count(),
            'A repeated event_id must not create a second webhook_events row.',
        );

        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'A repeated event_id must not dispatch a second ProcessStripeWebhookJob.',
        );

        // Effect unchanged — still exactly what the FIRST (and only) processing wrote.
        $this->assertSame(
            'past_due',
            Subscription::query()->where('user_id', $this->tenant->getKey())->value('stripe_status'),
        );

        $this->assertSame(
            1,
            Subscription::query()->where('user_id', $this->tenant->getKey())->count(),
            'Reprocessing must not create a second local subscription row.',
        );
    }

    public function test_an_invalid_signature_is_rejected_and_nothing_is_persisted(): void
    {
        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
        ]);

        $response = $this->postStripeWebhook($event, signatureHeader: 't=1700000000,v1=not-a-real-signature');

        $response->assertStatus(400);
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_an_event_for_an_unmapped_stripe_customer_is_recorded_as_failed_not_thrown(): void
    {
        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_orphan',
            'customer' => 'cus_does_not_exist_anywhere',
            'status' => 'active',
        ]);

        $response = $this->postStripeWebhook($event);

        // ADR-014 pct. 4 — un stripe_id nemapat e un eșec DE MAPARE, nu o excepție
        // necontrolată; Stripe n-are motiv să reîncerce.
        $response->assertOk();

        $stored = WebhookEvent::query()->sole();
        $this->assertSame(WebhookEvent::STATUS_FAILED, $stored->status);
        $this->assertNotNull($stored->error_message);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** Vezi `GenerateShippingLabelJobTest::workTheQueue()` — același motiv, aceeași formă. */
    private function workTheQueue(int $expected): void
    {
        $this->clearDatabaseTenantContext();

        for ($i = 0; $i < $expected; $i++) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--no-interaction' => true,
            ]);
        }

        $failed = DB::table('failed_jobs')->count();
        $this->assertSame(0, $failed, 'A queued Stripe webhook job failed unexpectedly — see failed_jobs.');
    }
}
