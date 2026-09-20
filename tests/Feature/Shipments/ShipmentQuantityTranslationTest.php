<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * ADR-022, plan-implementare.md „Lot I18N" Val 2 — pluralizarea `rules.shipments.
 * remaining_to_ship` (`trans_choice()`), pe cazul care justifică regula: capcana centrală
 * a lotului e că franceza tratează 0 ca SINGULAR, engleza ca plural.
 *
 * Scenariul de mai jos produce EXACT `remaining = 0` (nu doar `1`, unde engleza și franceza
 * ar arăta la fel din întâmplare — engleza tratează și 1 ca singular): o linie de 10, un
 * prim shipment care ia toate cele 10, apoi o a doua încercare de 1 lovește
 * `RemainingToShip::forLine() === 0`.
 */
class ShipmentQuantityTranslationTest extends TestCase
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

    public function test_zero_remaining_is_plural_in_english(): void
    {
        App::setLocale('en');

        $message = $this->attemptSecondShipmentAndCaptureMessage();

        // Regula standard engleză: `0` NU e `==1`, deci alege forma PLURALĂ — „units", nu
        // „unit". `MessageSelector::getPluralIndex('en', 0)` → `1` (implicit framework).
        $this->assertSame('Only 0 units are left to ship on this line.', $message);
    }

    public function test_zero_remaining_is_singular_in_french(): void
    {
        App::setLocale('fr');

        $message = $this->attemptSecondShipmentAndCaptureMessage();

        // Capcana centrală a lotului: `MessageSelector::getPluralIndex('fr', 0)` →
        // `($number == 0 || $number == 1) ? 0 : 1` = `0`, deci franceza alege forma
        // SINGULARĂ pentru 0 — diferit de engleză, cablat nativ în Laravel, nu în cod
        // scris de acest lot (vezi docblock-ul `lang/en/rules.php`).
        $this->assertSame('Il ne reste que 0 unité à expédier sur cette ligne.', $message);
    }

    private function attemptSecondShipmentAndCaptureMessage(): string
    {
        return TenantContext::run($this->tenant, function (): string {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $confirmed = (new ConfirmOrderAction)->execute($this->draftOrder([
                ['variant_id' => $variant->getKey(), 'quantity' => 10],
            ]), acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            // Primul shipment ia tot ce există pe linie — rămasul devine 0.
            (new CreateShipmentAction(new CarrierResolver))->execute($confirmed->fresh(), [$line->getKey() => 10]);

            try {
                (new CreateShipmentAction(new CarrierResolver))->execute($confirmed->fresh(), [$line->getKey() => 1]);
                $this->fail('Expected a ValidationException — nothing left on this line.');
            } catch (ValidationException $e) {
                return $e->errors()["lines.{$line->getKey()}"][0];
            }
        });
    }

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
