<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

/**
 * DOM-03 (audit 2026-09-23, docs/reviews/2026-09-23_audit/03-cod-domeniu.md) —
 * `App\Models\Tenant::subscriptions()` ordona doar după `created_at` (`timestamp(0)`,
 * `.ai/rules/tenancy.md`), fără niciun tiebreaker: două abonamente scrise în aceeași
 * secundă puteau ieși în orice ordine, nedeterminist. Testul de față scrie exact acel
 * caz — două rânduri `subscriptions` cu `created_at` identic — și verifică fixul
 * (`->orderBy('id', 'desc')` adăugat pe relație): cel scris ultimul (id mai mare)
 * trebuie să fie mereu primul.
 *
 * Fișier NOU, separat de `SubscriptionAccessTest`/`SubscriptionCancellationTest`
 * existente — nu testează accesul sau anularea, doar ordinea relației.
 */
class SubscriptionOrderingTest extends TestCase
{
    public function test_two_subscriptions_with_identical_created_at_are_ordered_by_id_as_a_tiebreaker(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));

        $older = Subscription::query()->create([
            'user_id' => $tenant->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_marlin_older',
            'stripe_status' => 'active',
        ]);

        $newer = Subscription::query()->create([
            'user_id' => $tenant->getKey(),
            'type' => 'default',
            'stripe_id' => 'sub_marlin_newer',
            'stripe_status' => 'active',
        ]);

        Carbon::setTestNow();

        // Premisa testului: fără ea, egalitatea de mai jos n-ar dovedi nimic — Postgres
        // ar avea deja un `created_at` diferit de departajat cu.
        $this->assertTrue(
            $older->created_at->equalTo($newer->created_at),
            'Premisă: ambele rânduri trebuie scrise cu exact același `created_at`.',
        );
        $this->assertGreaterThan($older->getKey(), $newer->getKey());

        $ordered = $tenant->subscriptions()->get();

        $this->assertSame(
            $newer->getKey(),
            $ordered->first()->getKey(),
            'Cu `created_at` egal, abonamentul cu `id` mai mare (scris ultimul) trebuie să câștige.',
        );
    }
}
