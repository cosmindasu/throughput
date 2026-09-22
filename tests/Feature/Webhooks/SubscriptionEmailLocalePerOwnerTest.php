<?php

namespace Tests\Feature\Webhooks;

use App\Mail\SubscriptionCanceledMail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * Lot I18N, Val 5 — gol lăsat deschis din Val 2 (plan-implementare.md, „Lot I18N"),
 * decis explicit acum: bucla per destinatar.
 *
 * Motivul exact pentru care testul ăsta trebuia să existe: cele trei email-uri de
 * facturare trimiteau prin `Mail::to($recipients)` cu `$recipients` un ARRAY de adrese.
 * `Illuminate\Mail\PendingMail::to()` citește `preferredLocale()`
 * (`Illuminate\Contracts\Translation\HasLocalePreference`) DOAR când primește UN SINGUR
 * model, nu un array — deci toți Owner-ii unui tenant primeau email-ul în ACEEAȘI limbă,
 * oricare ar fi fost `users.locale` al fiecăruia. Era latent fiindcă seed-ul de demo are
 * un singur Owner per tenant; devine vizibil abia la primul tenant cu doi Owner-i cu limbi
 * diferite — exact scenariul de mai jos. `App\Listeners\Billing\SendSubscriptionCanceledEmail`
 * și cele două listenere-soră au aceeași structură; un singur eveniment e suficient să
 * dovedească fixul, care e identic în toate trei.
 */
class SubscriptionEmailLocalePerOwnerTest extends TestCase
{
    use SignsStripeWebhooks;

    private Tenant $tenant;

    private User $ownerEn;

    private User $ownerFr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        $this->ownerEn = $this->makeMember($this->tenant, 'owner-en@throughput.dev', Permissions::OWNER);
        $this->ownerEn->forceFill(['locale' => 'en'])->save();

        $this->ownerFr = $this->makeMember($this->tenant, 'owner-fr@throughput.dev', Permissions::OWNER);
        $this->ownerFr->forceFill(['locale' => 'fr'])->save();

        $this->tenant->forceFill(['stripe_id' => 'cus_marlin_test'])->save();

        $this->clearDatabaseTenantContext();
    }

    public function test_two_owners_with_different_locales_each_get_their_own_email_in_their_own_language(): void
    {
        Mail::fake();

        $this->postStripeWebhook($this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'canceled',
        ], eventId: 'evt_cancel_two_owners'))->assertOk();

        $this->workTheQueue(2); // sync job + listener de email

        // DOUĂ email-uri, unul per Owner — nu unul singur trimis de două ori și nu unul
        // trimis unui array de doi destinatari (asta ar fi lăsat pe amândoi în aceeași
        // limbă, cf. docblock-ul clasei).
        Mail::assertSentTimes(SubscriptionCanceledMail::class, 2);

        Mail::assertSent(
            SubscriptionCanceledMail::class,
            fn (SubscriptionCanceledMail $mail): bool => $mail->hasTo($this->ownerEn->email)
                && ! $mail->hasTo($this->ownerFr->email)
                && $mail->hasSubject(trans('mail.subscription_canceled.subject', ['tenant' => $this->tenant->name], 'en')),
        );

        Mail::assertSent(
            SubscriptionCanceledMail::class,
            fn (SubscriptionCanceledMail $mail): bool => $mail->hasTo($this->ownerFr->email)
                && ! $mail->hasTo($this->ownerEn->email)
                && $mail->hasSubject(trans('mail.subscription_canceled.subject', ['tenant' => $this->tenant->name], 'fr')),
        );
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
