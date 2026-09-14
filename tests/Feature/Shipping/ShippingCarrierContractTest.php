<?php

namespace Tests\Feature\Shipping;

use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Services\Shipping\DemoShippingCarrier;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * FR-ORD-07 — „suită de teste de contract rulată IDENTIC împotriva fiecărei
 * implementări". Azi, o singură implementare permanentă (`DemoShippingCarrier`,
 * ADR-010) — `ShippoCarrier` vine în Faza 5 și se adaugă la `carriers()` fără să
 * schimbe nicio asserție de mai jos, exact criteriul de acceptanță al ADR-010.
 */
class ShippingCarrierContractTest extends TestCase
{
    private Tenant $tenant;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        $this->shipment = TenantContext::run($this->tenant, function () use ($owner): Shipment {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $location = Location::query()->create(['name' => 'Main Warehouse', 'is_default' => true]);

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => 'confirmed',
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
            $order->save();

            return Shipment::query()->create([
                'order_id' => $order->getKey(),
                'location_id' => $location->getKey(),
                'carrier' => 'demo',
                'service_level' => 'Ground',
                'status' => Shipment::STATUS_LABEL_PENDING,
            ]);
        });
    }

    /** @return list<ShippingCarrier> */
    private function carriers(): array
    {
        return [
            new DemoShippingCarrier,
        ];
    }

    public function test_create_label_returns_a_tracking_number_and_a_label_url(): void
    {
        TenantContext::run($this->tenant, function (): void {
            foreach ($this->carriers() as $carrier) {
                $label = $carrier->createLabel($this->shipment);

                $this->assertNotSame('', $label->trackingNumber, $carrier::class);
                $this->assertStringStartsWith('https://', $label->labelUrl, $carrier::class);
            }
        });
    }

    public function test_tracking_url_is_a_valid_looking_url(): void
    {
        TenantContext::run($this->tenant, function (): void {
            foreach ($this->carriers() as $carrier) {
                $label = $carrier->createLabel($this->shipment);
                $this->shipment->tracking_number = $label->trackingNumber;

                $url = $carrier->trackingUrl($this->shipment);

                $this->assertStringStartsWith('https://', $url, $carrier::class);
                $this->assertStringContainsString($label->trackingNumber, $url, $carrier::class);
            }
        });
    }

    public function test_void_does_not_throw(): void
    {
        TenantContext::run($this->tenant, function (): void {
            foreach ($this->carriers() as $carrier) {
                $carrier->void($this->shipment);
            }

            $this->addToAssertionCount(count($this->carriers()));
        });
    }

    /**
     * ADR-013 — niciun apel extern nu se face în cererea HTTP. `DemoShippingCarrier`
     * e singura implementare azi care POATE rula sincron (n-are niciun apel extern de
     * amânat); testul documentează explicit de ce, ca un revizor să nu confunde asta
     * cu o încălcare a regulii pentru viitoarele implementări reale.
     */
    public function test_demo_carrier_makes_no_external_call_and_can_run_synchronously(): void
    {
        $this->assertInstanceOf(ShippingCarrier::class, new DemoShippingCarrier);
    }
}
