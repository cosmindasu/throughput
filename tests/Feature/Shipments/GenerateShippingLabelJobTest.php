<?php

namespace Tests\Feature\Shipments;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Jobs\Shipping\GenerateShippingLabelJob;
use App\Models\Account;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\TenantCarrierSetting;
use App\Models\User;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\JobErrorMessage;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesOrders;
use Tests\Fixtures\Shipping\CarrierThatCancelsTheOrderDuringCreateLabel;
use Tests\Fixtures\Shipping\CarrierThatFailsUnexpectedly;
use Tests\Fixtures\Shipping\CarrierThatFailsWithAReportedReason;
use Tests\Fixtures\Shipping\FakeCarrierResolver;
use Tests\TestCase;

/**
 * `GenerateShippingLabelJob` — US-ORD-03, ADR-013. Rulat pe coada REALĂ (`database`,
 * `phpunit.xml`), niciodată `Queue::fake()`: contextul de tenant al jobului trebuie
 * restaurat de el însuși, nu moștenit din cererea/testul care l-a dispecerizat
 * (`.ai/rules/tenancy.md`, „Testele rulează cu coadă database, nu sync").
 */
class GenerateShippingLabelJobTest extends TestCase
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

        $this->clearDatabaseTenantContext();
    }

    public function test_a_successful_label_purchase_moves_the_shipment_to_label_purchased(): void
    {
        $shipmentId = $this->createPendingShipment();

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_PURCHASED, $shipment->status);
            $this->assertNotNull($shipment->tracking_number);
            $this->assertNotNull($shipment->label_url);
            $this->assertNull($shipment->error_message);
        });
    }

    /**
     * Code review P2 — o REZOLUȚIE eșuată (`provider = shippo`, adaptorul vine în Faza 5)
     * e mereu o problemă de CONFIGURARE, niciodată un `ShippingLabelFailed` raportat de
     * furnizor: mesajul e cel GENERIC, nu textul brut al excepției interne.
     */
    public function test_a_carrier_resolution_failure_stores_the_generic_message(): void
    {
        TenantContext::run($this->tenant, function (): void {
            TenantCarrierSetting::query()->create(['provider' => 'shippo', 'credentials' => [], 'is_active' => true]);
        });

        $shipmentId = $this->createPendingShipment();

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_FAILED, $shipment->status);
            $this->assertNull($shipment->tracking_number);
            // I18N-03 — `error_message` e acum o cheie codificată (`JobErrorMessage`), nu
            // textul brut: se afirmă pe valoarea RANDATĂ, exact ce ar vedea utilizatorul
            // prin `ShipmentResource`.
            $this->assertSame('The carrier could not create a label. Try again or contact support.', JobErrorMessage::render($shipment->error_message));
            $this->assertStringNotContainsString('Shippo', $shipment->error_message, 'Detaliul intern nu ajunge pe shipment.');
        });
    }

    /**
     * US-ORD-03 / code review P2 — `ShippingLabelFailed` e SINGURA excepție al cărei
     * mesaj ajunge intact pe `shipments.error_message`: motivul SPECIFIC raportat de
     * furnizor (nu „Something went wrong").
     */
    public function test_a_carrier_reported_failure_stores_its_specific_message(): void
    {
        $shipmentId = $this->createPendingShipment();

        $this->app->instance(CarrierResolver::class, new FakeCarrierResolver(new CarrierThatFailsWithAReportedReason));

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_FAILED, $shipment->status);
            $this->assertNull($shipment->tracking_number);
            $this->assertSame(CarrierThatFailsWithAReportedReason::MESSAGE, $shipment->error_message);
        });
    }

    /**
     * Code review P2 — orice ALTĂ excepție decât `ShippingLabelFailed` (rețea,
     * credențiale, un bug intern al adaptorului) NU își arată mesajul brut pe shipment —
     * un Viewer vede mesajul generic, nu detalii interne.
     */
    public function test_an_unexpected_carrier_failure_stores_a_generic_message_not_the_raw_one(): void
    {
        $shipmentId = $this->createPendingShipment();

        $this->app->instance(CarrierResolver::class, new FakeCarrierResolver(new CarrierThatFailsUnexpectedly));

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_FAILED, $shipment->status);
            // I18N-03 — vezi nota din `test_a_carrier_resolution_failure_stores_the_generic_message()`.
            $this->assertSame('The carrier could not create a label. Try again or contact support.', JobErrorMessage::render($shipment->error_message));
            $this->assertStringNotContainsString(
                CarrierThatFailsUnexpectedly::MESSAGE,
                $shipment->error_message,
                'Mesajul brut al unei erori interne nu trebuie să ajungă vizibil pe comandă.'
            );
        });
    }

    /**
     * Code review P1, apărare în adâncime — dacă shipment-ul e deja `label_pending` când
     * comanda nu mai e `confirmed`/`partially_fulfilled` (teoretic imposibil pe căile
     * normale după fix-ul din `CancelOrderAction`, provocat aici direct), jobul refuză
     * FĂRĂ niciun apel extern: `CarrierResolver` nu ar trebui nici măcar interogat.
     */
    public function test_the_job_refuses_without_any_external_call_when_the_order_is_no_longer_open(): void
    {
        $shipmentId = $this->createPendingShipment();

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);
            // Ocolește `CancelOrderAction` deliberat (fix-ul P1 ar refuza) — provoacă
            // exact starea pe care plasa de siguranță din job trebuie s-o prindă oricum.
            Order::query()->whereKey($shipment->order_id)->update(['status' => OrderStatus::Cancelled]);
        });

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_FAILED, $shipment->status);
            $this->assertNull($shipment->tracking_number);
            // I18N-03 — `error_message` e o cheie codificată cu un parametru AMÂNAT
            // (`:status`, vezi `JobErrorMessage::translatedParam()`); afirmă pe valoarea
            // randată, ca `ShipmentResource` ar produce-o.
            $rendered = JobErrorMessage::render($shipment->error_message);
            $this->assertStringContainsString('no longer open for shipping', $rendered);
            $this->assertStringContainsString('Cancelled', $rendered);
        });
    }

    /**
     * Code review P1, apărare în adâncime, a doua verificare — comanda iese din
     * `confirmed`/`partially_fulfilled` CHIAR ÎN TIMPUL apelului extern (simulat de
     * `CarrierThatCancelsTheOrderDuringCreateLabel`, care întoarce totuși o etichetă
     * „reușită"): eticheta NU se activează pe un shipment orfan.
     */
    public function test_the_job_refuses_to_activate_a_label_when_the_order_stopped_being_open_mid_call(): void
    {
        $shipmentId = $this->createPendingShipment();

        $this->app->instance(
            CarrierResolver::class,
            new FakeCarrierResolver(new CarrierThatCancelsTheOrderDuringCreateLabel($this->tenant->getKey()))
        );

        $this->workTheQueue(1);

        TenantContext::run($this->tenant, function () use ($shipmentId): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_FAILED, $shipment->status, 'Eticheta "reușită" nu trebuie activată.');
            $this->assertNull($shipment->tracking_number);
            $this->assertStringContainsString('no longer open for shipping', JobErrorMessage::render($shipment->error_message));
        });
    }

    /**
     * Idempotent — o reluare a jobului peste un shipment DEJA `label_purchased` nu
     * cumpără o a doua etichetă (nu suprascrie tracking/label existente).
     */
    public function test_retrying_the_job_over_an_already_purchased_shipment_does_nothing(): void
    {
        $shipmentId = $this->createPendingShipment();
        $this->workTheQueue(1);

        $trackingBefore = TenantContext::run($this->tenant, fn () => Shipment::query()->findOrFail($shipmentId)->tracking_number);

        // Reluare manuală a JOBULUI (nu a acțiunii) — simulează o redelivery de coadă.
        $job = new GenerateShippingLabelJob($this->tenant->getKey(), $shipmentId);
        $job->handle(new CarrierResolver);

        TenantContext::run($this->tenant, function () use ($shipmentId, $trackingBefore): void {
            $shipment = Shipment::query()->findOrFail($shipmentId);

            $this->assertSame(Shipment::STATUS_LABEL_PURCHASED, $shipment->status);
            $this->assertSame($trackingBefore, $shipment->tracking_number, 'Nu s-a cumpărat o a doua etichetă.');
        });
    }

    private function createPendingShipment(): string
    {
        return TenantContext::run($this->tenant, function (): string {
            $variant = $this->makeVariant();
            $this->setInventory($variant, $this->location, onHand: 100);

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => 'draft',
                'currency' => 'USD',
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            $order->orderLines()->save(new OrderLine([
                'variant_id' => $variant->getKey(),
                'description' => 'Test line',
                'quantity' => 10,
                'unit_price' => 10,
                'discount' => 0,
                'line_total' => 100,
            ]));

            $confirmed = (new ConfirmOrderAction)->execute($order, acknowledgeBackorder: false);
            $line = $confirmed->orderLines()->firstOrFail();

            $shipment = (new CreateShipmentAction(new CarrierResolver))->execute($confirmed, [$line->getKey() => 5]);

            return $shipment->getKey();
        });
    }

    /**
     * Vezi `QueuedJobContextTest::workTheQueue()` — același motiv, aceeași formă.
     *
     * Faza 5, lotul E (ADR-007) — `createPendingShipment()` creează Account/Order/Variant,
     * care declanșează acum și `App\Observers\ActivityLogObserver` (job `WriteActivityLogEntry`
     * pe ACEEAȘI coadă `default`, ÎNAINTEA jobului de etichetă în ordinea FIFO) — un singur
     * `--once` ar procesa jurnalul, nu `GenerateShippingLabelJob`. `--stop-when-empty` golește
     * tot ce e în coadă în acest punct (jurnalul + jobul de etichetă), echivalent cu `$expected`
     * apeluri `--once` reușite atunci când niciunul nu eșuează — cazul acestui fișier, unde
     * eșecul de curier e prins ÎN job (`label_failed`), nu aruncat ca excepție de coadă.
     */
    private function workTheQueue(int $expected): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);

        $failed = DB::table('failed_jobs')->count();
        $this->assertSame(0, $failed, 'A queued shipping label job failed unexpectedly — see failed_jobs.');
    }
}
