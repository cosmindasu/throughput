<?php

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\ActivateCarrierAction;
use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BR-ORD-03 — „exact un rând `is_active = true` per tenant; schimbarea furnizorului
 * activ dezactivează automat cel anterior, în ACEEAȘI tranzacție".
 *
 * Proba de concurență explicită (task brief lot D) trăiește separat, în
 * `ActivateCarrierConcurrencyTest` — are nevoie să dezactiveze împachetarea în
 * tranzacție a harnessului (vezi docblock-ul de acolo), pe care restul testelor din
 * ACEST fișier se bazează pentru rollback automat între teste.
 */
class ActivateCarrierActionTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_activating_shippo_deactivates_demo_in_the_same_transaction(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create(['provider' => 'demo', 'is_active' => true]);

            (new ActivateCarrierAction)->execute('shippo', ['api_key' => 'shippo_test_abc123']);

            $this->assertSame(
                'shippo',
                TenantCarrierSetting::query()->where('is_active', true)->value('provider')
            );
            $this->assertSame(1, TenantCarrierSetting::query()->where('is_active', true)->count());

            $demo = TenantCarrierSetting::query()->where('provider', 'demo')->firstOrFail();
            $this->assertFalse($demo->is_active);
        });
    }

    /**
     * Reversul — comutarea înapoi trebuie să funcționeze la fel de curat, de câte ori e
     * nevoie: invarianta nu e „o singură dată la prima activare", e permanentă.
     */
    public function test_switching_back_and_forth_always_leaves_exactly_one_active_row(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $action = new ActivateCarrierAction;

            $action->execute('shippo', ['api_key' => 'shippo_test_abc123']);
            $action->execute('demo', null);
            $action->execute('shippo', null);

            $this->assertSame(1, TenantCarrierSetting::query()->where('is_active', true)->count());
            $this->assertSame('shippo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));

            // A treia activare a lui `shippo` n-a trimis din nou `credentials` — cheia
            // salvată la prima activare trebuie păstrată, nu ștearsă.
            $shippo = TenantCarrierSetting::query()->where('provider', 'shippo')->firstOrFail();
            $this->assertSame('shippo_test_abc123', $shippo->credentials['api_key']);
        });
    }

    public function test_activating_shippo_without_an_api_key_is_rejected_before_any_row_changes(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create(['provider' => 'demo', 'is_active' => true]);

            try {
                (new ActivateCarrierAction)->execute('shippo', null);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('credentials.api_key', $e->errors());
            }

            // Nimic nu s-a schimbat — `demo` rămâne activ, niciun rând `shippo` creat.
            $this->assertSame('demo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));
            $this->assertFalse(TenantCarrierSetting::query()->where('provider', 'shippo')->exists());
        });
    }

    public function test_two_tenants_activate_independently(): void
    {
        $northgate = $this->makeTenant('northgate', 'Northgate Distribution');
        $this->makeMember($northgate, 'owner@northgate.throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            (new ActivateCarrierAction)->execute('shippo', ['api_key' => 'shippo_test_marlin_key']);
        });

        TenantContext::run($northgate, function (): void {
            (new ActivateCarrierAction)->execute('demo', null);
        });

        TenantContext::run($this->tenant, function (): void {
            $this->assertSame('shippo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));
        });

        TenantContext::run($northgate, function (): void {
            $this->assertSame('demo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));
        });
    }

    /**
     * Audit de securitate P1 — al doilea strat al interdicției „exclusiv chei sandbox"
     * (FR-ORD-01/BR-DEMO-03), verificat direct la nivelul Action-ului: chiar dacă cineva
     * ar ocoli `UpdateCarrierSettingRequest` (regexul de acolo e primul strat), o cheie
     * `shippo_live_...` tot nu ajunge NICIODATĂ persistată.
     */
    public function test_activating_shippo_with_a_live_looking_key_is_rejected_before_any_row_changes(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create(['provider' => 'demo', 'is_active' => true]);

            try {
                (new ActivateCarrierAction)->execute('shippo', ['api_key' => 'shippo_live_abc123']);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('credentials.api_key', $e->errors());
                $this->assertStringContainsString('sandbox', $e->errors()['credentials.api_key'][0]);
            }

            $this->assertSame('demo', TenantCarrierSetting::query()->where('is_active', true)->value('provider'));
            $this->assertFalse(TenantCarrierSetting::query()->where('provider', 'shippo')->exists());
        });
    }

    /**
     * Audit P3 — un string de doar spații trecea vechiul test `!== ''` și activa
     * furnizorul cu o cheie inutilizabilă, descoperită abia la primul shipment.
     */
    public function test_activating_shippo_with_a_whitespace_only_key_is_rejected(): void
    {
        TenantContext::run($this->tenant, function (): void {
            try {
                (new ActivateCarrierAction)->execute('shippo', ['api_key' => '   ']);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('credentials.api_key', $e->errors());
            }

            $this->assertFalse(TenantCarrierSetting::query()->where('provider', 'shippo')->exists());
        });
    }

    /**
     * Audit P3 — whitelist explicit pe `credentials`: o sub-cheie în plus nu ajunge
     * persistată, criptată dar necontrolată.
     */
    public function test_extra_credential_fields_are_stripped_before_saving(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $setting = (new ActivateCarrierAction)->execute('shippo', [
                'api_key' => 'shippo_test_abc123',
                'webhook_secret' => 'not-whitelisted',
            ]);

            $this->assertSame(['api_key' => 'shippo_test_abc123'], $setting->credentials);
        });
    }
}
