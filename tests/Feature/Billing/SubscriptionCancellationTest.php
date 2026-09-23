<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * BR-BILL-05, specs.md §12.2/§20.5 — „la tranziția către canceled... se setează
 * tenants.subscription_canceled_at = now(). Timp de 30 de zile... tenantul rămâne
 * reactivabil." Ancora e scrisă EXACT O DATĂ, la prima tranziție — nu la fiecare
 * webhook ulterior pe un abonament deja `canceled` (idempotență de nivel mai fin decât
 * deduplicarea `webhook_events`: aici e vorba de DOUĂ evenimente DIFERITE care ajung
 * amândouă la `stripe_status = canceled`).
 */
class SubscriptionCancellationTest extends TestCase
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

    public function test_the_transition_to_canceled_stamps_subscription_canceled_at_once(): void
    {
        $this->assertNull($this->tenant->fresh()->subscription_canceled_at);

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'unpaid',
        ], eventId: 'evt_to_unpaid'))->assertOk();
        $this->workTheQueue();

        $this->assertNull(
            $this->tenant->fresh()->subscription_canceled_at,
            'unpaid must not start the retention window — only canceled does (BR-BILL-05).',
        );

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_to_canceled'))->assertOk();
        $this->workTheQueue();

        $firstStamp = $this->tenant->fresh()->subscription_canceled_at;
        $this->assertNotNull($firstStamp);

        // Un SECOND webhook, evenimentul DIFERIT, dar ajungând la ACELAȘI status
        // `canceled` (ex: Stripe retrimite `customer.subscription.deleted` separat) — nu
        // trebuie să împingă din nou ancora.
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.deleted', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_deleted_after_canceled'))->assertOk();
        $this->workTheQueue();

        $this->assertTrue(
            $firstStamp->equalTo($this->tenant->fresh()->subscription_canceled_at),
            'subscription_canceled_at must be stamped once, at the FIRST transition into canceled.',
        );
    }

    /**
     * GDPR-01, ADR-012 („Implementare") — bug de reactivare: reactivarea prin portalul
     * Stripe (`customer.subscription.updated`, status `active`, fără `cancel_at_period_end`/
     * `cancel_at`/`canceled_at`) trebuie să golească `subscription_canceled_at`, altfel
     * `PurgeCanceledTenantsJob` ar număra o fereastră de 30 de zile pornind de la o anulare
     * care nu mai e reală.
     */
    /**
     * Audit GDPR-01 (2026-09-23, P1) — Stripe nu garantează ordinea de livrare, iar un
     * eveniment eșuat e reîncercat mai târziu. O anulare VECHE, sosită după reactivare, nu are
     * voie să rescrie starea: altfel abonamentul rămânea `canceled` local (deși activ în
     * Stripe), iar `PurgeCanceledTenantsJob` ar fi șters un tenant plătitor după 30 de zile.
     */
    public function test_a_stale_cancellation_delivered_after_the_reactivation_is_ignored(): void
    {
        $reactivation = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
        ], eventId: 'evt_reactivated');
        $reactivation['created'] = now()->subMinutes(5)->getTimestamp();

        $staleCancellation = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
            'canceled_at' => now()->subMinutes(10)->getTimestamp(),
        ], eventId: 'evt_to_canceled');
        $staleCancellation['created'] = now()->subMinutes(10)->getTimestamp();

        // Livrare inversată: reactivarea întâi, anularea (mai veche) abia după.
        $this->postStripeWebhook($reactivation)->assertOk();
        $this->workTheQueue();
        $this->postStripeWebhook($staleCancellation)->assertOk();
        $this->workTheQueue();

        $subscription = Subscription::query()->where('stripe_id', 'sub_marlin_test')->sole();

        $this->assertSame('active', $subscription->stripe_status);
        $this->assertNull($subscription->ends_at);
        $this->assertNull($this->tenant->fresh()->subscription_canceled_at);
    }

    /** Ordinea normală rămâne aplicată: un eveniment mai NOU trece. */
    public function test_a_newer_event_still_overrides_an_older_one(): void
    {
        $cancellation = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_older_cancel');
        $cancellation['created'] = now()->subMinutes(10)->getTimestamp();

        $reactivation = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
        ], eventId: 'evt_newer_reactivation');
        $reactivation['created'] = now()->subMinutes(5)->getTimestamp();

        $this->postStripeWebhook($cancellation)->assertOk();
        $this->workTheQueue();
        $this->assertNotNull($this->tenant->fresh()->subscription_canceled_at);

        $this->postStripeWebhook($reactivation)->assertOk();
        $this->workTheQueue();

        $this->assertSame('active', Subscription::query()->where('stripe_id', 'sub_marlin_test')->sole()->stripe_status);
        $this->assertNull($this->tenant->fresh()->subscription_canceled_at);
    }

    public function test_reactivation_after_cancellation_clears_subscription_canceled_at(): void
    {
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_to_canceled'))->assertOk();
        $this->workTheQueue();

        $this->assertNotNull($this->tenant->fresh()->subscription_canceled_at);

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
        ], eventId: 'evt_reactivated'))->assertOk();
        $this->workTheQueue();

        $this->assertNull(
            $this->tenant->fresh()->subscription_canceled_at,
            'A reactivation with no scheduled cancellation must clear the retention window anchor.',
        );
    }

    /**
     * O a doua anulare, DUPĂ o reactivare, trebuie să înceapă o fereastră NOUĂ — nu doar
     * să lase ancora goală moștenită de la reactivare, și nu ancora VECHE dinaintea
     * reactivării (idempotența BR-BILL-05 e per TRANZIȚIE, nu per abonament).
     */
    public function test_a_new_cancellation_after_reactivation_stamps_a_fresh_date(): void
    {
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_to_canceled'))->assertOk();
        $this->workTheQueue();
        $firstStamp = $this->tenant->fresh()->subscription_canceled_at;

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
        ], eventId: 'evt_reactivated'))->assertOk();
        $this->workTheQueue();
        $this->assertNull($this->tenant->fresh()->subscription_canceled_at);

        $this->travel(2)->days();

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_to_canceled_again'))->assertOk();
        $this->workTheQueue();

        $secondStamp = $this->tenant->fresh()->subscription_canceled_at;
        $this->assertNotNull($secondStamp);
        $this->assertTrue($secondStamp->greaterThan($firstStamp), 'The second cancellation must stamp a NEW date, not reuse the one from before reactivation.');
    }

    /**
     * `--stop-when-empty`, NU un număr fix de `--once` (formă anterioară a acestui test) —
     * de când tranzițiile de status declanșează și emailurile din §12.2 (review-ul lotului,
     * pct. 1), fiecare webhook poate lăsa în urmă 0 SAU 1 job suplimentar (listener-ul de
     * email), în funcție de starea locală anterioară — un număr fix de iterații ar rămâne
     * cu un job nedrenat, procesat abia la apelul URMĂTOR, în ordine FIFO greșită (reprodus:
     * `subscription_canceled_at` rămânea `null` fiindcă jobul de sincronizare aștepta după
     * un listener de email nedrenat).
     */
    private function workTheQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
