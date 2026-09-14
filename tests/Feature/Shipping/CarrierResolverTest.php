<?php

namespace Tests\Feature\Shipping;

use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use App\Services\Shipping\CarrierResolver;
use App\Services\Shipping\DemoShippingCarrier;
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
     * `shippo` n'a niciun adaptor încă (Faza 5) — o configurare validă în bază nu
     * trebuie să degradeze tăcut la Demo, ca o greșeală de configurare să nu treacă
     * neobservată.
     */
    public function test_a_configured_but_unimplemented_provider_throws_instead_of_silently_falling_back(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create([
                'provider' => 'shippo',
                'is_active' => true,
            ]);

            $this->expectException(RuntimeException::class);

            (new CarrierResolver)->resolve();
        });
    }
}
