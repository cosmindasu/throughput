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
