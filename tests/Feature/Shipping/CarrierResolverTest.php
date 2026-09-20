<?php

namespace Tests\Feature\Shipping;

use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use App\Services\Shipping\CarrierResolver;
use App\Services\Shipping\DemoShippingCarrier;
use App\Services\Shipping\ShippoCarrier;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-010/§11.5 — selecția per tenant din `tenant_carrier_settings`, cu `demo`
 * implicit „pentru tenanții publici" și, mai general aici, pentru orice tenant fără
 * nicio configurare încă (BR-ORD-03: exact un rând `is_active` per tenant).
 */
class CarrierResolverTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_resolves_demo_when_nothing_is_configured(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $carrier = (new CarrierResolver)->resolve();

            $this->assertInstanceOf(DemoShippingCarrier::class, $carrier);
        });
    }

    public function test_resolves_demo_when_the_active_setting_is_demo(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create([
                'provider' => 'demo',
                'is_active' => true,
            ]);

            $carrier = (new CarrierResolver)->resolve();

            $this->assertInstanceOf(DemoShippingCarrier::class, $carrier);
        });
    }

    /**
     * Faza 5 — `shippo` are acum adaptor (`ShippoCarrier`), instanțiat cu credențialele
     * DECRIPTATE ale rândului activ (`encrypted:array`, ADR-010).
     */
    public function test_resolves_shippo_carrier_when_the_active_setting_is_shippo_with_an_api_key(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create([
                'provider' => 'shippo',
                'credentials' => ['api_key' => 'shippo_test_abc123'],
                'is_active' => true,
            ]);

            $carrier = (new CarrierResolver)->resolve();

            $this->assertInstanceOf(ShippoCarrier::class, $carrier);
        });
    }

    /**
     * O cheie lipsă e mereu o problemă de CONFIGURARE (ecranul de Settings ar fi trebuit
     * s-o ceară — `App\Actions\Shipping\ActivateCarrierAction`), niciodată o degradare
     * tăcută la Demo, care ar ascunde o configurare greșită: o setare `shippo` fără
     * `credentials.api_key` rămâne o stare validă în bază, dar `resolve()` refuză
     * s-o folosească ÎNAINTE de orice apel extern (`GenerateShippingLabelJobTest`
     * verifică efectul asupra shipment-ului — mesaj generic, niciun apel de curierat).
     */
    public function test_a_shippo_setting_without_an_api_key_throws_before_any_external_call(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create([
                'provider' => 'shippo',
                'credentials' => [],
                'is_active' => true,
            ]);

            $this->expectException(RuntimeException::class);

            (new CarrierResolver)->resolve();
        });
    }
}
