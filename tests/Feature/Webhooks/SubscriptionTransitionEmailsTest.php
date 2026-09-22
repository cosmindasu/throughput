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
        // Lot I18N, Val 5 — raza de explozie a buclei per destinatar (fiecare Owner ia
        // ACUM un `Mailable` propriu, cf. `App\Listeners\Billing\SendSubscriptionUnpaidEmail`).
        // `Mail::assertSentCount(1)` era adevărat aici doar pentru că tenantul din `setUp()`
        // are UN SINGUR Owner activ — nu verifica idempotența pe `event_id`, care e chiar
        // ce testul ăsta pretinde să dovedească. Forma corectă: numărul de email-uri PER
        // Owner (`assertSentTimes`, nu totalul global al fake-ului) rămâne NESCHIMBAT
        // înainte/după al doilea webhook care confirmă aceeași tranziție.
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
        // Exact câți Owner-i activi are tenantul din setUp() ($this->owner) — NU o
        // constantă arbitrară.
        Mail::assertSentTimes(SubscriptionUnpaidMail::class, 1);
        $sentAfterFirstTransition = Mail::sent(SubscriptionUnpaidMail::class)->count();

        // Un AL DOILEA webhook, eveniment DIFERIT, care confirmă TOT `unpaid` (Stripe poate
        // retrimite `customer.subscription.updated` pentru alte câmpuri neschimbate) — NU
        // trebuie să retrimită emailul: tranziția s-a întâmplat deja o dată.
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'unpaid',
        ], eventId: 'evt_to_unpaid_2'))->assertOk();
        $this->workTheQueue(1); // doar sync job — niciun email nou de dispecerizat

        // Idempotența pe `event_id`: numărul rămâne identic cu cel de dinainte de al
        // doilea webhook, nu cu o valoare fixă care s-ar nimeri să coincidă.
        Mail::assertSentTimes(SubscriptionUnpaidMail::class, $sentAfterFirstTransition);
    }

    public function test_the_transition_to_canceled_emails_the_owner_exactly_once(): void
    {
        // Lot I18N, Val 5 — aceeași notă ca la testul de `unpaid` de mai sus:
        // `Mail::assertSentCount(1)` era adevărat doar pentru că tenantul are UN SINGUR
        // Owner activ, nu pentru că verifica idempotența pe `event_id`. Forma corectă
        // rămâne per Owner (`assertSentTimes`) și compară explicit numărul dinainte/după
        // al doilea eveniment de anulare.
        Mail::fake();

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_first_cancel'))->assertOk();
        $this->workTheQueue(2); // sync job + listener de email

        Mail::assertSent(SubscriptionCanceledMail::class, fn (SubscriptionCanceledMail $mail): bool => $mail->hasTo($this->owner->email));
        // Exact câți Owner-i activi are tenantul din setUp() ($this->owner) — NU o
        // constantă arbitrară.
        Mail::assertSentTimes(SubscriptionCanceledMail::class, 1);
        $sentAfterFirstCancellation = Mail::sent(SubscriptionCanceledMail::class)->count();

        // Al doilea eveniment, tot spre `canceled` (ex: `customer.subscription.deleted`
        // separat) — NU retrimite.
        $this->postStripeWebhook($this->stripeEvent('customer.subscription.deleted', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_second_cancel'))->assertOk();
        $this->workTheQueue(1); // doar sync job

        // Idempotența pe `event_id`: numărul rămâne identic cu cel de dinainte de al
        // doilea eveniment, nu cu o valoare fixă care s-ar nimeri să coincidă.
        Mail::assertSentTimes(SubscriptionCanceledMail::class, $sentAfterFirstCancellation);
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
