<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
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
 * `CreateShipmentAction` — §11.2 pas 4, US-ORD-02. Verificat direct pe acțiune (fără
 * HTTP), la fel ca `ConfirmOrderActionTest`: RBAC + izolare au propriul test HTTP
 * (`ShipmentsHttpTest`).
 */
class CreateShipmentActionTest extends TestCase
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

    public function test_creating_a_shipment_saves_it_label_pending_and_queues_the_label_job(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);

            $line = $confirmed->orderLines()->firstOrFail();

            // Faza 5, lotul E (ADR-007) — nu mai presupunem coadă GOALĂ: crearea contului/
            // variantei/comenzii de mai sus declanșează acum și `ActivityLogObserver` (câte
            // un job `WriteActivityLogEntry` per scriere). Verificăm DELTA introdusă de
            // `CreateShipmentAction`, nu un total absolut.
            $jobsBefore = DB::table('jobs')->count();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 6]);

            $this->assertSame(Shipment::STATUS_LABEL_PENDING, $shipment->status);
            $this->assertSame($this->location->getKey(), $shipment->location_id);
            $this->assertSame('demo', $shipment->carrier, 'Fără `tenant_carrier_settings`, implicit `demo` (ADR-010).');
            $this->assertSame(6, $shipment->shipmentLines->first()->quantity);

            // ADR-013 — UN job în plus pus în coadă (eticheta), nu un apel sincron.
            $this->assertSame($jobsBefore + 1, DB::table('jobs')->count());

            // Creare shipment nu atinge STOCUL — doar `MarkShipmentShippedAction` face asta.
            $level = $variant->inventoryLevels()->where('location_id', $this->location->getKey())->first();
            $this->assertSame(100, $level->on_hand);
            $this->assertSame(10, $level->reserved);
        });
    }

    public function test_a_partial_shipment_is_allowed_and_leaves_the_rest_open(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 4]);

            // Rămasul de expediat scade cu partea deja revendicată de shipment-ul deschis.
            $line->refresh();
            $line->load('shipmentLines.shipment');
            $this->assertSame(6, RemainingToShip::forLine($line));
        });
    }

    public function test_a_quantity_over_the_remaining_amount_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            try {
                (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 11]);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("lines.{$line->getKey()}", $e->errors());
            }

            $this->assertSame(0, Shipment::query()->count(), 'Nimic scris la un refuz.');
        });
    }

    /**
     * Un al doilea shipment care ar depăși ce a mai rămas DUPĂ primul (deschis) e refuzat
     * — rămasul scade cu shipment-urile deschise, nu doar cu `quantity_fulfilled`.
     */
    public function test_a_second_shipment_cannot_overlap_an_open_first_one(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            (new CreateShipmentAction(new CarrierResolver))->execute($confirmed->fresh(), [$line->getKey() => 6]);

            try {
                (new CreateShipmentAction(new CarrierResolver))->execute($confirmed->fresh(), [$line->getKey() => 5]);
                $this->fail('Expected a ValidationException — only 4 left (10 − 6 already claimed).');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("lines.{$line->getKey()}", $e->errors());
                $this->assertStringContainsString('Only 4', $e->errors()["lines.{$line->getKey()}"][0]);
            }
        });
    }

    public function test_creating_a_shipment_from_a_draft_order_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $order = $this->draftOrder([['variant_id' => $variant->getKey(), 'quantity' => 10]]);
            $line = $order->orderLines()->firstOrFail();

            try {
                (new CreateShipmentAction(new CarrierResolver))->execute($order, [$line->getKey() => 1]);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        });
    }

    public function test_creating_a_shipment_with_no_positive_quantity_is_refused(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            try {
                (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 0]);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }
        });
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
