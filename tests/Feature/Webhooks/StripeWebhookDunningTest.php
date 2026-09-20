<?php

namespace Tests\Feature\Webhooks;

use App\Mail\DunningPaymentFailedMail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * FR-BILL-04, specs.md §12.2 — „Listener propriu... pe invoice.payment_failed... trimite
 * email către Owner cu attempt_count". Cashier NU tratează implicit acest eveniment
 * (research citat de plan §11) — fără acest lot, un Owner ar afla de eșec abia când
 * `stripe_status` schimbă, posibil zile mai târziu.
 */
class StripeWebhookDunningTest extends TestCase
{
    use SignsStripeWebhooks;

    private Tenant $tenant;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $this->tenant->forceFill(['stripe_id' => 'cus_marlin_test'])->save();

        $this->clearDatabaseTenantContext();
    }

    public function test_a_payment_failed_event_emails_every_active_owner_with_the_attempt_count(): void
    {
        Mail::fake();

        $event = $this->stripeEvent('invoice.payment_failed', [
            'id' => 'in_test_1',
            'customer' => 'cus_marlin_test',
            'attempt_count' => 3,
        ]);

        $this->postStripeWebhook($event)->assertOk();

        // Deux joburi în lanț: ProcessStripeWebhookJob (dispecerizează evenimentul de
        // domeniu) → SendPaymentFailedDunningEmail (ShouldQueue, trimite efectiv).
        $this->workTheQueue(2);

        Mail::assertSent(DunningPaymentFailedMail::class, function (DunningPaymentFailedMail $mail): bool {
            return $mail->attemptCount === 3
                && $mail->hasTo($this->owner->email)
                && ! $mail->hasTo($this->manager->email);
        });
    }

    public function test_the_same_payment_failed_event_id_only_emails_once(): void
    {
        Mail::fake();

        $event = $this->stripeEvent('invoice.payment_failed', [
            'id' => 'in_test_2',
            'customer' => 'cus_marlin_test',
            'attempt_count' => 1,
        ], eventId: 'evt_dunning_repeat');

        $this->postStripeWebhook($event)->assertOk();
        $this->workTheQueue(2);

        $this->postStripeWebhook($event)->assertOk();
        $this->workTheQueue(0);

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

        $failed = DB::table('failed_jobs')->count();
        $this->assertSame(0, $failed, 'A queued webhook/dunning job failed unexpectedly — see failed_jobs.');
    }
}
