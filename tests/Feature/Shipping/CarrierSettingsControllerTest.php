<?php

namespace Tests\Feature\Shipping;

use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings → Shipping (FR-ORD-06, §7.4 „Setări curierat", ADR-010) — Owner-only, la fel
 * ca `SentEmailsTest` pentru RBAC-ul din același modul: o clasă dedicată pentru
 * ecran+RBAC+mascare, separată de `ActivateCarrierActionTest` (BR-ORD-03 la nivel de
 * acțiune) — consecvent cu convenția deja stabilită în proiect.
 */
class CarrierSettingsControllerTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    /**
     * §7.4 — singurul rând din toată matricea unde Managerul n-are nici măcar `R`
     * (`Permissions::forRoles()` exclude explicit `carrier_settings.*` din setul lui).
     */
    public function test_only_owner_can_open_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/shipping')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Shipping/Index'));

        $this->actingAs($this->manager)->get('/marlin/settings/shipping')->assertForbidden();
        $this->actingAs($this->agent)->get('/marlin/settings/shipping')->assertForbidden();
        $this->actingAs($this->viewer)->get('/marlin/settings/shipping')->assertForbidden();
    }

    public function test_only_owner_can_change_the_active_carrier(): void
    {
        $payload = ['provider' => 'demo'];

        $this->actingAs($this->manager)->post('/marlin/settings/shipping', $payload)->assertForbidden();
        $this->actingAs($this->agent)->post('/marlin/settings/shipping', $payload)->assertForbidden();
        $this->actingAs($this->viewer)->post('/marlin/settings/shipping', $payload)->assertForbidden();

        $this->actingAs($this->owner)
            ->post('/marlin/settings/shipping', ['provider' => 'shippo', 'credentials' => ['api_key' => 'shippo_test_abc123']])
            ->assertRedirect('/marlin/settings/shipping');
    }

    public function test_the_screen_lists_both_known_providers_even_before_anything_is_configured(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/shipping')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('providers', 2)
                ->where('providers.0.provider', 'demo')
                ->where('providers.0.isActive', false)
                ->where('providers.0.configured', true)
                ->where('providers.1.provider', 'shippo')
                ->where('providers.1.isActive', false)
                ->where('providers.1.configured', false)
                ->where('providers.1.credentialPreview', null)
                ->where('can.manage', true)
            );
    }

    public function test_activating_shippo_requires_an_api_key(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/shipping', ['provider' => 'shippo'])
            ->assertRedirect()
            ->assertSessionHasErrors('credentials.api_key');

        TenantContext::run($this->marlin, function (): void {
            $this->assertFalse(TenantCarrierSetting::query()->where('provider', 'shippo')->exists());
        });
    }

    /**
     * Audit de securitate P1 — FR-ORD-01/BR-DEMO-03: „exclusiv chei sandbox", verificat
     * acum server-side, primul strat (`UpdateCarrierSettingRequest`), nu doar textul din
     * hint-ul UI. Contează mai ales aici: `DemoLoginController` autentifică orice
     * vizitator ca Owner demo, deci ecranul ar fi fost altfel un oracol gratuit pentru
     * verificat dacă o cheie furată mai e live.
     */
    public function test_activating_shippo_with_a_live_looking_key_is_rejected_at_the_http_layer(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/shipping', [
                'provider' => 'shippo',
                'credentials' => ['api_key' => 'shippo_live_abc123'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('credentials.api_key');

        TenantContext::run($this->marlin, function (): void {
            $this->assertFalse(TenantCarrierSetting::query()->where('provider', 'shippo')->exists());
        });
    }

    /**
     * BR-AUD-01 — cheia întreagă nu ajunge NICIODATĂ înapoi la client, în niciun prop,
     * sub nicio formă: verificat pe SERIALIZAREA COMPLETĂ a paginii, nu doar pe câmpul
     * `credentialPreview` (o scurgere accidentală prin alt câmp ar trece neobservată de
     * o asserție punctuală).
     */
    public function test_the_raw_api_key_never_appears_anywhere_in_the_page_response(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/shipping', [
            'provider' => 'shippo',
            'credentials' => ['api_key' => 'shippo_test_super_secret_abcd1234'],
        ]);

        $response = $this->actingAs($this->owner)->get('/marlin/settings/shipping');
        $response->assertOk();

        $page = json_encode($response->inertiaPage());

        $this->assertStringNotContainsString('shippo_test_super_secret_abcd1234', $page);
        $this->assertStringContainsString('1234', $page);

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.1.configured', true)
            ->where('providers.1.credentialPreview', '•••• 1234')
            ->where('providers.1.isActive', true)
            ->where('providers.0.isActive', false)
        );
    }

    /**
     * Formularul mascat (FE, `resources/js/Pages/Settings/Shipping/Index.tsx`) omite
     * `credentials` cu totul cât timp utilizatorul nu scrie o cheie nouă — verificat aici
     * la nivel de request: activarea repetată fără `credentials` păstrează cheia salvată.
     */
    public function test_reactivating_without_resubmitting_credentials_keeps_the_saved_key(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/shipping', [
            'provider' => 'shippo',
            'credentials' => ['api_key' => 'shippo_test_abc123'],
        ]);

        $this->actingAs($this->owner)->post('/marlin/settings/shipping', ['provider' => 'demo']);
        $this->actingAs($this->owner)->post('/marlin/settings/shipping', ['provider' => 'shippo']);

        TenantContext::run($this->marlin, function (): void {
            $shippo = TenantCarrierSetting::query()->where('provider', 'shippo')->firstOrFail();

            $this->assertTrue($shippo->is_active);
            $this->assertSame('shippo_test_abc123', $shippo->credentials['api_key']);
        });
    }

    public function test_activating_demo_deactivates_shippo(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/shipping', [
            'provider' => 'shippo',
            'credentials' => ['api_key' => 'shippo_test_abc123'],
        ]);

        $this->actingAs($this->owner)
            ->post('/marlin/settings/shipping', ['provider' => 'demo'])
            ->assertRedirect('/marlin/settings/shipping')
            ->assertSessionHas('success');

        TenantContext::run($this->marlin, function (): void {
            $this->assertSame('demo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));
            $this->assertSame(1, TenantCarrierSetting::query()->where('is_active', true)->count());
        });
    }

    /**
     * Audit de securitate P2 — o rotație de `APP_KEY` fără `APP_PREVIOUS_KEYS` face
     * `credentials` irecuperabil (`DecryptException: The MAC is invalid`). Jobul de
     * etichetă degrada deja corect; ecranul arunca 500 la simpla încărcare a paginii.
     * Simulează rotația legând un `Encrypter` nou (cheie diferită) în container — cel cu
     * care rândul a fost criptat rămâne cel vechi, deci decriptarea eșuează exact cum ar
     * eșua după o rotație reală fără chei vechi păstrate.
     */
    public function test_a_decryption_failure_is_treated_as_not_configured_with_a_clear_message(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/shipping', [
            'provider' => 'shippo',
            'credentials' => ['api_key' => 'shippo_test_abc123'],
        ]);

        $cipher = config('app.cipher');
        $originalEncrypter = app('encrypter');

        app()->instance('encrypter', new Encrypter(Encrypter::generateKey($cipher), $cipher));
        Crypt::clearResolvedInstance('encrypter');

        try {
            $response = $this->actingAs($this->owner)->get('/marlin/settings/shipping');
            $response->assertOk();

            $response->assertInertia(fn (AssertableInertia $page) => $page
                ->where('providers.1.provider', 'shippo')
                ->where('providers.1.configured', false)
                ->where('providers.1.credentialPreview', null)
                ->where(
                    'providers.1.credentialError',
                    'Stored credentials could not be read (the encryption key may have changed) — re-enter the API key.'
                )
            );
        } finally {
            app()->instance('encrypter', $originalEncrypter);
            Crypt::clearResolvedInstance('encrypter');
        }
    }
}
