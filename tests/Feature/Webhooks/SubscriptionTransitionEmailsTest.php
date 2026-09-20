<?php

namespace Tests\Feature\Webhooks;

use App\Mail\SubscriptionCanceledMail;
use App\Mail\SubscriptionUnpaidMail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Review-ul lotului, pct. 1 — specs.md §12.2, tabelul de degradare: email O SINGURĂ DATĂ
 * la tranziția `past_due → unpaid`, și „la tranziție" spre `canceled`. Distinct de
 * `StripeWebhookDunningTest` (FR-BILL-04, un email la FIECARE `invoice.payment_failed`).
 */
class SubscriptionTransitionEmailsTest extends TestCase
{
    use SignsStripeWebhooks;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->tenant->forceFill(['stripe_id' => 'cus_marlin_test'])->save();

        $this->clearDatabaseTenantContext();
    }

    public function test_the_transition_to_unpaid_emails_the_owner_exactly_once(): void
    {
        Mail::fake();

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'past_due',
        ], eventId: 'evt_to_past_due'))->assertOk();
        $this->workTheQueue(1);

        Mail::assertNotSent(SubscriptionUnpaidMail::class);

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'unpaid',
        ], eventId: 'evt_to_unpaid_1'))->assertOk();
        $this->workTheQueue(2); // sync job + listener de email

        Mail::assertSent(SubscriptionUnpaidMail::class, fn (SubscriptionUnpaidMail $mail): bool => $mail->hasTo($this->owner->email));
        Mail::assertSentCount(1);

        // Un AL DOILEA webhook, eveniment DIFERIT, care confirmă TOT `unpaid` (Stripe poate
        // retrimite `customer.subscription.updated` pentru alte câmpuri neschimbate) — NU
        // trebuie să retrimită emailul: tranziția s-a întâmplat deja o dată.
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'unpaid',
        ], eventId: 'evt_to_unpaid_2'))->assertOk();
        $this->workTheQueue(1); // doar sync job — niciun email nou de dispecerizat

        Mail::assertSentCount(1);
    }

    public function test_the_transition_to_canceled_emails_the_owner_exactly_once(): void
    {
        Mail::fake();

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_first_cancel'))->assertOk();
        $this->workTheQueue(2); // sync job + listener de email

        Mail::assertSent(SubscriptionCanceledMail::class, fn (SubscriptionCanceledMail $mail): bool => $mail->hasTo($this->owner->email));
        Mail::assertSentCount(1);

        // Al doilea eveniment, tot spre `canceled` (ex: `customer.subscription.deleted`
        // separat) — NU retrimite.
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.deleted', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_second_cancel'))->assertOk();
        $this->workTheQueue(1); // doar sync job

        Mail::assertSentCount(1);
    }

    private function workTheQueue(int $expected): void
    {
        $this->clearDatabaseTenantContext();

        for ($i = 0; $i < $expected; $i++) {
            $this->artisan('queue:work', [
                '--once' => true,
                '--no-interaction' => true,
            ]);
        }

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
