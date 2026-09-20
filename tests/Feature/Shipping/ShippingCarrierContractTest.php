<?php

namespace Tests\Feature\Shipping;

use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Services\Shipping\DemoShippingCarrier;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippoCarrier;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FR-ORD-07 — „suită de teste de contract rulată IDENTIC împotriva fiecărei
 * implementări". Faza 5: DOUĂ implementări permanente (`DemoShippingCarrier`,
 * `ShippoCarrier` — ADR-010, `easypost` scos la 2026-09-12, vezi nota din ADR și
 * `CarrierResolverTest`). Orice a treia implementare viitoare se adaugă la `carriers()`
 * fără să schimbe nicio asserție de mai jos — exact criteriul de acceptanță al ADR-010.
 *
 * `Http::fake()` global în `setUp()`: ShippoCarrier chiar face cereri HTTP
 * (`api.goshippo.com`) — NICIUN test din acest proiect face un apel real către Shippo,
 * în niciun mediu (regulă absolută a lotului D). `DemoShippingCarrier` ignoră complet
 * fake-ul (n-are niciun apel extern, ADR-010).
 */
class ShippingCarrierContractTest extends TestCase
{
    private Tenant $tenant;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.goshippo.com/shipments/' => Http::response([
                'object_id' => 'shp_test',
                'status' => 'SUCCESS',
                'messages' => [],
                'rates' => [[
                    'object_id' => 'rate_test',
                    'amount' => '12.50',
                    'currency' => 'USD',
                    'provider' => 'USPS',
                    'servicelevel' => ['name' => 'Priority Mail'],
                ]],
            ], 201),
            'api.goshippo.com/transactions/' => Http::response([
                'object_id' => 'txn_test',
                'status' => 'SUCCESS',
                'tracking_number' => 'SHIPPO_TEST_TRACK_123',
                'label_url' => 'https://shippo-delivery-sandbox.s3.amazonaws.com/test-label.pdf',
                'messages' => [],
            ], 201),
        ]);

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        $this->shipment = TenantContext::run($this->tenant, function () use ($owner): Shipment {
            $account = new Account([
                'name' => 'Northwind Industrial Supply LLC',
                'shipping_address' => [
                    'line1' => '500 Industrial Pkwy',
                    'city' => 'Reno',
                    'state' => 'NV',
                    'postal_code' => '89501',
                    'country' => 'US',
                ],
            ]);
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
            new ShippoCarrier(['api_key' => 'shippo_test_dummy']),
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
     * e singura implementare care POATE rula sincron (n-are niciun apel extern de
     * amânat); testul documentează explicit de ce, ca un revizor să nu confunde asta
     * cu o încălcare a regulii pentru `ShippoCarrier`.
     */
    public function test_demo_carrier_makes_no_external_call_and_can_run_synchronously(): void
    {
        $this->assertInstanceOf(ShippingCarrier::class, new DemoShippingCarrier);
    }

    /**
     * Reversul testului de mai sus — `ShippoCarrier` CHIAR face apeluri externe (motivul
     * pentru care `GenerateShippingLabelJob` există deloc, ADR-013), în DOUĂ cereri
     * secvențiale reale de API Shippo (shipment → tarife, apoi tranzacție → etichetă).
     */
    public function test_shippo_carrier_makes_external_calls(): void
    {
        TenantContext::run($this->tenant, function (): void {
            (new ShippoCarrier(['api_key' => 'shippo_test_dummy']))->createLabel($this->shipment);
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.goshippo.com/shipments/');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.goshippo.com/transactions/');
    }
}
