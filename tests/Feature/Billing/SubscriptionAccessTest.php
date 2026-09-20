<?php

namespace Tests\Feature\Billing;

use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

/**
 * specs.md §12.2 (tabelul de degradare pe 3 trepte), BR-BILL-04 — un singur loc
 * (`App\Support\Billing\SubscriptionAccessPolicy`) decide nivelul de acces, aplicat de
 * `App\Http\Middleware\EnsureSubscriptionAccess` pe rutele de SCRIERE ale ÎNTREGULUI
 * workspace, nu doar Billing.
 *
 * `saved-views.store`/`accounts.index` sunt folosite ca rută de scriere/citire
 * REPREZENTATIVĂ — mecanismul e agnostic de resursă (verifică doar METODA cererii), deci
 * nu are rost să repete testul pe fiecare modul. `saved-views.store`, nu `accounts.store`:
 * vizualizările salvate PRIVATE sunt singura scriere din §7.4 disponibilă la TOATE cele
 * patru roluri, inclusiv Viewer — exact acoperirea „toate cele patru roluri" cerută de
 * lot, într-un singur endpoint.
 */
class SubscriptionAccessTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->tenant, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    public function test_no_local_subscription_row_yet_means_full_access(): void
    {
        $this->assertSavedViewCreationSucceeds($this->owner);
    }

    public function test_past_due_does_not_change_access_for_any_role(): void
    {
        // BR-BILL-03 — Stripe încă reîncearcă, eșecul nu e definitiv.
        $this->setSubscriptionStatus(StripeSubscription::STATUS_PAST_DUE);

        foreach ([$this->owner, $this->manager, $this->agent, $this->viewer] as $user) {
            $this->assertSavedViewCreationSucceeds($user);
        }
    }

    public function test_unpaid_allows_reads_for_every_role_but_blocks_writes_for_every_role(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_UNPAID);

        foreach ([$this->owner, $this->manager, $this->agent, $this->viewer] as $user) {
            $this->actingAs($user)->get('/marlin/accounts')->assertOk();
        }

        // §12.2, tabelul de degradare — „declanșare export... permise", explicit distinct
        // de vizualizare simplă. Exportul e o CITIRE (GET) livrată prin coadă (§13.2), deci
        // trece prin ACELAȘI ramificaj „metodă safe" — verificat direct, nu doar presupus,
        // pentru toate patru rolurile (`bulk.export`, §7.4).
        foreach ([$this->owner, $this->manager, $this->agent, $this->viewer] as $user) {
            $this->actingAs($user)->get('/marlin/accounts/export')->assertOk();
        }

        // US-BILL-04 — „indiferent de rolul meu (inclusiv Owner)". Toate patru rolurile au
        // `saved_views.manage_own` (§7.4) — toate patru ar reuși în mod normal (`active`),
        // toate patru sunt blocate aici.
        foreach ([$this->owner, $this->manager, $this->agent, $this->viewer] as $user) {
            $before = $this->savedViewsCount();

            $response = $this->actingAs($user)->post('/marlin/saved-views', [
                'resource_type' => 'accounts',
                'name' => 'Blocked While Unpaid',
                'visibility' => 'private',
            ]);

            $response->assertForbidden();
            $this->assertSame($before, $this->savedViewsCount(), 'A write must not go through while unpaid.');
        }
    }

    public function test_unpaid_still_allows_reaching_the_billing_page(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_UNPAID);

        // Fără drept de a fi blocat: altfel un Owner n-ar mai putea niciodată actualiza
        // cardul care l-ar debloca (US-BILL-04).
        $this->actingAs($this->owner)->get('/marlin/settings/billing')->assertOk();
    }

    public function test_canceled_redirects_every_other_page_to_billing(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_CANCELED);

        $response = $this->actingAs($this->owner)->get('/marlin/accounts');

        $response->assertRedirect('/marlin/settings/billing');

        $this->actingAs($this->owner)->get('/marlin/settings/billing')->assertOk();
    }

    /**
     * P3 securitate (review-ul lotului, pct. 6) — reprodus înainte de fix: un Manager
     * redirectat spre `settings.billing.index` primea 403 acolo (`billing.view` e
     * Owner-only, §7.4) — capăt de drum, fără nicio pagină funcțională, fără explicație.
     */
    public function test_canceled_shows_a_dedicated_screen_to_a_member_without_billing_view(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_CANCELED);

        $response = $this->actingAs($this->manager)->get('/marlin/accounts');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Billing/AccessBlocked')
            ->has('owners', 1)
            ->where('owners.0.email', $this->owner->email)
        );
    }

    private function assertSavedViewCreationSucceeds(User $user): void
    {
        $before = $this->savedViewsCount();

        $response = $this->actingAs($user)->post('/marlin/saved-views', [
            'resource_type' => 'accounts',
            'name' => 'Allowed View '.Str::random(6),
            'visibility' => 'private',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($before + 1, $this->savedViewsCount());
    }

    /**
     * `SavedView` e scopat de tenant (RLS + global scope) — o interogare directă, în afara
     * unei cereri HTTP sau a `TenantContext::run()`, aruncă explicit (ADR-014), nu întoarce
     * tăcut un rezultat greșit.
     */
    private function savedViewsCount(): int
    {
        return TenantContext::run($this->tenant, fn () => SavedView::query()->count());
    }

    private function setSubscriptionStatus(string $stripeStatus): void
    {
        TenantContext::run($this->tenant, function () use ($stripeStatus): void {
            Subscription::query()->updateOrCreate(
                ['user_id' => $this->tenant->getKey(), 'stripe_id' => 'sub_marlin_test'],
                ['type' => 'default', 'stripe_status' => $stripeStatus],
            );
        });

        $this->clearDatabaseTenantContext();
    }
}
