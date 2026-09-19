<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Actions\Shipments\DiscardShipmentAction;
use App\Actions\Shipments\RetryShippingLabelAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\Orders\RemainingToShip;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * `RetryShippingLabelAction`/`DiscardShipmentAction` — US-ORD-03 („reîncercare manuală"),
 * task brief item 3 („renunțarea... eliberează cantitățile").
 */
class RetryAndDiscardShipmentActionTest extends TestCase
{
    use CreatesOrders;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
            $this->location = $this->makeDefaultLocation();
        });
    }

    public function test_retrying_a_failed_label_requeues_the_job(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $shipment = $this->failedShipment();

            DB::table('jobs')->delete();

            $retried = (new RetryShippingLabelAction)->execute($shipment);

            $this->assertSame(Shipment::STATUS_LABEL_PENDING, $retried->status);
            $this->assertNull($retried->error_message);
            $this->assertSame(1, DB::table('jobs')->count(), 'ADR-013 — un nou job de etichetă, nu un apel sincron.');
        });
    }

    public function test_retrying_a_shipment_that_is_not_failed_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 50);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 5],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 5]);
            $this->assertSame(Shipment::STATUS_LABEL_PENDING, $shipment->status);

            try {
                (new RetryShippingLabelAction)->execute($shipment);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_discarding_a_failed_shipment_removes_it_and_frees_its_lines(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $shipment = $this->failedShipment();
            $orderLineId = $shipment->shipmentLines->first()->order_line_id;

            (new DiscardShipmentAction)->execute($shipment);

            $this->assertNull(Shipment::query()->find($shipment->getKey()), 'Rândul chiar dispare.');
            $this->assertSame(0, ShipmentLine::query()->where('shipment_id', $shipment->getKey())->count(), 'Cascadă.');

            $line = OrderLine::query()->findOrFail($orderLineId);
            $this->assertSame(5, RemainingToShip::forLine($line), 'Cantitatea revine disponibilă pentru un shipment nou.');
        });
    }

    public function test_discarding_a_shipment_that_is_not_failed_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 50);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 5],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 5]);

            try {
                (new DiscardShipmentAction)->execute($shipment);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            $this->assertNotNull(Shipment::query()->find($shipment->getKey()));
        });
    }

    private function failedShipment(): Shipment
    {
        $variant = $this->makeVariant();
        $this->setInventory($variant, $this->location, onHand: 50);

        $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
            ['variant_id' => $variant->getKey(), 'quantity' => 5],
        ]), acknowledgeBackorder: false);
        $line = $confirmed->orderLines()->firstOrFail();

        $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 5]);
        $shipment->update(['status' => Shipment::STATUS_LABEL_FAILED, 'error_message' => 'Destination address failed validation.']);

        return $shipment->fresh(['shipmentLines']);
    }

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $lines
     */
    private function draftOrder(array $lines): Order
    {
        $order = new Order([
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'status' => OrderStatus::Draft,
            'currency' => 'USD',
        ]);
        $order->created_by = $this->owner->getKey();
        $order->save();

        foreach ($lines as $line) {
            $orderLine = new OrderLine([
                'order_id' => $order->getKey(),
                'variant_id' => $line['variant_id'],
                'description' => 'Test line',
                'quantity' => $line['quantity'],
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 10 * $line['quantity'],
            ]);
            $orderLine->save();
        }

        return $order;
    }
}
