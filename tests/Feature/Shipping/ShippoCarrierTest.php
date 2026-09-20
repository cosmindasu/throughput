<?php

namespace Tests\Feature\Shipping;

use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Services\Shipping\ShippingLabelFailed;
use App\Services\Shipping\ShippoCarrier;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `ShippoCarrier` — ADR-010, Faza 5, task brief lot D. Dincolo de suita de contract
 * (`ShippingCarrierContractTest`, care verifică doar forma comună a interfeței), acest
 * fișier verifică comportamentul SPECIFIC adaptorului: fluxul real în două cereri,
 * distincția „eșec raportat" vs „eroare internă" (contractul din docblock-ul
 * `ShippingCarrier`), timeout-ul + reîncercarea explicite, și fetch-ul adresei de
 * destinație FĂRĂ niciun context de tenant ambiental — exact cum îl cheamă
 * `GenerateShippingLabelJob` în producție (ADR-013/014).
 */
class ShippoCarrierTest extends TestCase
{
    private Tenant $tenant;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

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
                'carrier' => 'shippo',
                'service_level' => 'Ground',
                'status' => Shipment::STATUS_LABEL_PENDING,
            ]);
        });

        // Contextul de mai sus se pierde la commit (`TenantContext::run`, ADR-014) — exact
        // ca într-un job real, DUPĂ prima tranzacție scurtă a `GenerateShippingLabelJob`,
        // ÎNAINTE de apelul extern. Fiecare test pornește fără niciun context ambiental.
        $this->clearDatabaseTenantContext();
    }

    private function carrier(): ShippoCarrier
    {
        return new ShippoCarrier(['api_key' => 'shippo_test_dummy_key']);
    }

    /** @return array<string, mixed> */
    private function successfulShipmentResponse(string $rateId = 'rate_test', string $amount = '12.50'): array
    {
        return [
            'object_id' => 'shp_test',
            'status' => 'SUCCESS',
            'messages' => [],
            'rates' => [[
                'object_id' => $rateId,
                'amount' => $amount,
                'currency' => 'USD',
                'provider' => 'USPS',
                'servicelevel' => ['name' => 'Priority Mail'],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function successfulTransactionResponse(): array
    {
        return [
            'object_id' => 'txn_test',
            'status' => 'SUCCESS',
            'tracking_number' => 'SHIPPO_TEST_TRACK_123',
            'label_url' => 'https://shippo-delivery-sandbox.s3.amazonaws.com/test-label.pdf',
            'messages' => [],
        ];
    }

    public function test_a_successful_purchase_returns_the_cheapest_rate_as_cost(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response([
                'object_id' => 'shp_test',
                'status' => 'SUCCESS',
                'messages' => [],
                'rates' => [
                    ['object_id' => 'rate_expensive', 'amount' => '45.00', 'currency' => 'USD', 'provider' => 'FedEx', 'servicelevel' => ['name' => 'Overnight']],
                    ['object_id' => 'rate_cheap', 'amount' => '8.20', 'currency' => 'USD', 'provider' => 'USPS', 'servicelevel' => ['name' => 'Ground']],
                ],
            ], 201),
            'api.goshippo.com/transactions/' => Http::response($this->successfulTransactionResponse(), 201),
        ]);

        $label = $this->carrier()->createLabel($this->shipment);

        $this->assertSame('SHIPPO_TEST_TRACK_123', $label->trackingNumber);
        $this->assertSame('https://shippo-delivery-sandbox.s3.amazonaws.com/test-label.pdf', $label->labelUrl);
        $this->assertSame(8.20, $label->cost);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.goshippo.com/transactions/'
            && $request['rate'] === 'rate_cheap');
    }

    public function test_it_authenticates_with_a_shippo_token_header(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response($this->successfulShipmentResponse(), 201),
            'api.goshippo.com/transactions/' => Http::response($this->successfulTransactionResponse(), 201),
        ]);

        (new ShippoCarrier(['api_key' => 'shippo_test_abc123']))->createLabel($this->shipment);

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'ShippoToken shippo_test_abc123'));
    }

    /**
     * ADR-013/014 — reproduce EXACT cum `GenerateShippingLabelJob` cheamă
     * `createLabel()`: fără niciun context de tenant activ în container/PostgreSQL
     * (`setUp()` îl golește explicit). Dacă `destinationAddress()` ar presupune un
     * context încă legat, acest test ar arunca `TenantContextMissingException` în loc
     * să trimită adresa corectă.
     */
    public function test_it_fetches_the_destination_address_with_no_ambient_tenant_context(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response($this->successfulShipmentResponse(), 201),
            'api.goshippo.com/transactions/' => Http::response($this->successfulTransactionResponse(), 201),
        ]);

        $this->carrier()->createLabel($this->shipment);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.goshippo.com/shipments/') {
                return false;
            }

            $addressTo = $request['address_to'];

            return $addressTo['name'] === 'Northwind Industrial Supply LLC'
                && $addressTo['street1'] === '500 Industrial Pkwy'
                && $addressTo['city'] === 'Reno'
                && $addressTo['state'] === 'NV'
                && $addressTo['zip'] === '89501'
                && $addressTo['country'] === 'US';
        });
    }

    /**
     * Contractul `ShippingCarrier` (US-ORD-03) — Shippo răspunde `2xx` chiar și pentru o
     * adresă pe care n-o poate livra: eroarea e ÎN CORP (`rates: []` + `messages`), nu în
     * codul HTTP. `ShippingLabelFailed` cu mesajul EXACT, niciodată reformulat.
     */
    public function test_a_shipment_level_reported_failure_throws_shipping_label_failed_with_the_exact_message(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response([
                'object_id' => 'shp_test',
                'status' => 'ERROR',
                'messages' => [
                    ['source' => 'address_to', 'code' => 'invalid_address', 'text' => 'Street address is not deliverable (ZIP+4 mismatch).'],
                ],
                'rates' => [],
            ], 201),
        ]);

        try {
            $this->carrier()->createLabel($this->shipment);
            $this->fail('Expected ShippingLabelFailed.');
        } catch (ShippingLabelFailed $e) {
            $this->assertSame('Street address is not deliverable (ZIP+4 mismatch).', $e->getMessage());
        }

        // Eșecul e la primul apel — al doilea (`/transactions/`) nu trebuie încercat
        // fără un `rate` valid.
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.goshippo.com/transactions/');
    }

    /**
     * Aceeași distincție, la al doilea apel: rata a existat, dar CUMPĂRAREA etichetei
     * a fost respinsă (ex. „service unavailable" de la transportatorul ales).
     */
    public function test_a_transaction_level_reported_failure_throws_shipping_label_failed_with_the_exact_message(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response($this->successfulShipmentResponse(), 201),
            'api.goshippo.com/transactions/' => Http::response([
                'object_id' => 'txn_test',
                'status' => 'ERROR',
                'messages' => [
                    ['source' => 'USPS', 'code' => 'service_unavailable', 'text' => 'Selected service is temporarily unavailable for this route.'],
                ],
            ], 201),
        ]);

        try {
            $this->carrier()->createLabel($this->shipment);
            $this->fail('Expected ShippingLabelFailed.');
        } catch (ShippingLabelFailed $e) {
            $this->assertSame('Selected service is temporarily unavailable for this route.', $e->getMessage());
        }
    }

    /**
     * Contractul `ShippingCarrier` — credențiale greșite/expirate NU sunt un eșec
     * RAPORTAT despre expedierea asta, sunt o eroare a INTEGRĂRII: tipul ei natural
     * (`RequestException`), niciodată `ShippingLabelFailed`. `GenerateShippingLabelJob`
     * o tratează ca eroare internă (mesaj generic pe shipment, detalii doar în log).
     */
    public function test_an_authentication_error_is_an_internal_error_not_a_reported_one(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        $this->expectException(RequestException::class);

        $this->carrier()->createLabel($this->shipment);
    }

    /**
     * Timeout/sandbox căzut PE APELUL DE TARIFE (`/shipments/`, o citire) — reîncercat
     * STRICT pe `ConnectionException` (docblock-ul clasei), niciodată tratat ca eșec
     * raportat. Cu `RETRY_ATTEMPTS = 2`, o conexiune mereu eșuată epuizează exact 2
     * încercări, apoi lasă excepția să iasă cu tipul ei natural — internă, cu detalii
     * doar în log (nu pe shipment).
     */
    public function test_a_persistent_connection_failure_is_retried_then_surfaces_as_a_connection_exception(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::sequence()
                ->pushFailedConnection('Connection timed out talking to the carrier sandbox.')
                ->pushFailedConnection('Connection timed out talking to the carrier sandbox.'),
        ]);

        $this->expectException(ConnectionException::class);

        try {
            $this->carrier()->createLabel($this->shipment);
        } finally {
            Http::assertSentCount(2);
        }
    }

    /**
     * Audit de securitate P2 — o `ConnectionException` PE CUMPĂRARE (`/transactions/`)
     * NU se reîncearcă deloc, spre deosebire de apelul de tarife: un timeout de CITIRE a
     * răspunsului nu înseamnă că Shippo n-a procesat cererea, iar Shippo nu documentează
     * un header de idempotență de care ne-am putea baza pentru un al doilea `POST`
     * identic. O singură încercare, apoi eroare internă — siguranța vine de la
     * `GenerateShippingLabelJob` (verifică `label_pending` de două ori), nu de la adaptor.
     */
    public function test_a_connection_failure_during_the_purchase_call_is_not_retried(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response($this->successfulShipmentResponse(), 201),
            'api.goshippo.com/transactions/' => Http::sequence()
                ->pushFailedConnection('Connection reset while purchasing the label.'),
        ]);

        $this->expectException(ConnectionException::class);

        try {
            $this->carrier()->createLabel($this->shipment);
        } finally {
            // 1 pentru `/shipments/` + EXACT 1 pentru `/transactions/` — nicio reîncercare
            // pe cumpărare, chiar dacă `/transactions/` ar mai avea răspunsuri în coadă.
            Http::assertSentCount(2);
        }
    }

    /**
     * Reversul testului de mai sus — o pană TRANZITORIE care se remediază la a doua
     * încercare trebuie să reușească normal (jobul n-are nevoie de o a doua trecere prin
     * coadă pentru un sughiț de rețea de o fracțiune de secundă).
     */
    public function test_a_transient_connection_failure_that_recovers_on_retry_succeeds(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::sequence()
                ->pushFailedConnection('Connection reset by peer.')
                ->push($this->successfulShipmentResponse(), 201),
            'api.goshippo.com/transactions/' => Http::response($this->successfulTransactionResponse(), 201),
        ]);

        $label = $this->carrier()->createLabel($this->shipment);

        $this->assertSame('SHIPPO_TEST_TRACK_123', $label->trackingNumber);
        Http::assertSentCount(3);
    }

    /**
     * Nimic din aplicație nu apelează încă `void()` — un no-op explicit (docblock-ul
     * clasei) e mai corect decât a preface o anulare fără identificatorul de tranzacție
     * pe care Shippo l-ar cere.
     */
    public function test_void_is_a_safe_no_op_and_makes_no_http_call(): void
    {
        Http::fake();

        $this->carrier()->void($this->shipment);

        Http::assertNothingSent();
    }

    public function test_tracking_url_does_not_require_any_http_call(): void
    {
        Http::fake();

        $this->shipment->tracking_number = 'SHIPPO_TEST_TRACK_123';

        $url = $this->carrier()->trackingUrl($this->shipment);

        $this->assertSame('https://tracking.goshippo.com/SHIPPO_TEST_TRACK_123', $url);
        Http::assertNothingSent();
    }

    /**
     * `CarrierResolver` e cel care garantează azi că `ShippoCarrier` nu primește
     * niciodată o cheie goală (`resolveShippo()` aruncă înainte) — verificat totuși aici,
     * la nivelul adaptorului: o cheie goală produce un antet `ShippoToken` gol, deci un
     * 401 Shippo real, tratat ca eroare INTERNĂ (`RuntimeException`/`RequestException`),
     * niciodată `ShippingLabelFailed`.
     */
    public function test_an_empty_api_key_never_produces_a_reported_failure(): void
    {
        Http::fake([
            'api.goshippo.com/shipments/' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        try {
            (new ShippoCarrier([]))->createLabel($this->shipment);
            $this->fail('Expected an internal error.');
        } catch (RequestException $e) {
            $this->assertSame(401, $e->response->status());
        }
    }
}
