<?php

namespace Tests\Feature\Webhooks;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings → Webhook health — specs.md §25.2 („ecran de operare", intern) și criteriul de
 * acceptanță din §12.3 („`error_message` populat, VIZIBIL într-un ecran de operare").
 *
 * Miza acestui lot: ecranul distinge `ignored` de `failed`. Ambele sunt rânduri terminale
 * cu mesaj, dar numai unul e o problemă a noastră.
 */
class WebhookHealthScreenTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();

        $this->recordEvent('evt_failed_1', WebhookEvent::STATUS_FAILED, 'Subscription sync blew up.');
        $this->recordEvent('evt_ignored_1', WebhookEvent::STATUS_IGNORED, 'Not for this deployment: Stripe customer cus_elsewhere …');
        $this->recordEvent('evt_processed_1', WebhookEvent::STATUS_PROCESSED, null);
    }

    public function test_the_screen_separates_ignored_from_failed_in_its_counts(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/WebhookHealth/Index')
                ->where('counts.failed', 1)
                ->where('counts.ignored', 1)
                ->where('counts.processed', 1)
                ->has('events', 3)
            );
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks?status=ignored')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('events.0.status', WebhookEvent::STATUS_IGNORED)
                ->where('filter.status', WebhookEvent::STATUS_IGNORED)
            );

        // Valoare necunoscută → filtrul cade pe „orice status", nu pe o listă goală
        // inexplicabilă (și nu ajunge niciodată într-un `where` cu date din URL).
        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks?status=bogus')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 3)->where('filter.status', null));
    }

    /**
     * Ecranul NU trimite niciodată `payload` în props: `webhook_events` e o tabelă de
     * DEPLOYMENT, fără `tenant_id` și fără RLS (§19.1) — vezi docblock-ul controllerului.
     */
    public function test_the_screen_never_ships_the_event_payload(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('events.0.payload'));
    }

    public function test_only_owner_can_open_it(): void
    {
        $this->actingAs($this->owner)->get('/marlin/settings/webhooks')->assertOk();
        $this->actingAs($this->manager)->get('/marlin/settings/webhooks')->assertForbidden();
        $this->actingAs($this->viewer)->get('/marlin/settings/webhooks')->assertForbidden();
    }

    /**
     * SEC-02 (audit 2026-09-23) — `App\Support\SingleOwnerDeployment::active()`. Config
     * demo REALĂ (FR-TEN-01): trei tenanți, un singur Owner comun tuturor. Cu un singur
     * tenant (`marlin`, celelalte teste din acest fișier), garda trece trivial — asta nu
     * dovedește nimic despre semnalul „Owner comun". Aici mai adaug DOI tenanți și fac
     * din `$this->owner` Owner și acolo, ca interogarea `model_has_roles` (nu cazul
     * banal `tenantCount <= 1`) să fie cea exercitată.
     */
    public function test_the_guard_lets_the_screen_through_when_every_tenant_shares_the_same_owner(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Metal Works');
        $northgate = $this->makeTenant('northgate', 'Northgate Traders');

        $this->makeMember($cascade, $this->owner->email, Permissions::OWNER, $this->owner);
        $this->makeMember($northgate, $this->owner->email, Permissions::OWNER, $this->owner);

        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/WebhookHealth/Index')
                ->has('events', 3)
            );
    }

    /**
     * SEC-02 — al doilea tenant (`northgate`) NU împarte niciun Owner cu `marlin`:
     * proprietarul lui `marlin` (`$this->owner`) nu are rol Owner acolo, iar Owner-ul lui
     * `northgate` nu are rol Owner în `marlin`. Exact scenariul pe care docblock-ul
     * controllerului îl numește „tenantul devine o organizație independentă" — ecranul
     * trebuie să refuze, nu doar să-l lase pe Owner-ul lui `marlin` să vadă evenimentele
     * (potențial) ale altui proprietar.
     */
    public function test_the_guard_refuses_when_a_tenant_has_a_different_owner(): void
    {
        $northgate = $this->makeTenant('northgate', 'Northgate Traders');
        $this->makeMember($northgate, 'other.owner@example.com', Permissions::OWNER);

        $this->actingAs($this->owner)
            ->get('/marlin/settings/webhooks')
            ->assertForbidden();
    }

    private function recordEvent(string $eventId, string $status, ?string $message): void
    {
        WebhookEvent::query()->create([
            'source' => WebhookEvent::SOURCE_STRIPE,
            'event_id' => $eventId,
            'type' => 'customer.subscription.updated',
            'payload' => ['id' => $eventId, 'data' => ['object' => ['customer' => 'cus_x']]],
            'payload_hash' => hash('sha256', $eventId),
            'status' => $status,
            'received_at' => now(),
            'error_message' => $message,
        ]);
    }
}
