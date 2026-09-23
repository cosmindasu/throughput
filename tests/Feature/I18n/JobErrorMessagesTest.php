<?php

namespace Tests\Feature\I18n;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Jobs\Bulk\PlanBulkOperationJob;
use App\Jobs\Shipping\GenerateShippingLabelJob;
use App\Jobs\System\FailStuckBulkOperationsJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\DataExportRequest;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shipping\CarrierResolver;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\JobErrorMessage;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;

/**
 * I18N-03 — pentru fiecare din cele patru `Resource`-uri care serializează `error_message`
 * (`BulkOperationResource`, `ShipmentResource`, `ReportRunResource`,
 * `DataExportRequestResource`), o cheie codificată (`App\Support\JobErrorMessage`) se
 * traduce corect în locale-ul CERERII curente — pe modelul `ActivityLocaleTest`: lanțul REAL
 * de middleware (`SetLocale`), `users.locale` pe utilizator, niciodată `App::setLocale()`
 * chemat direct.
 *
 * Trei joburi REALE (nu fixture-uri scrise de mână) declanșate pe calea de eroare —
 * `App\Jobs\Bulk\PlanBulkOperationJob`, `App\Jobs\System\FailStuckBulkOperationsJob`,
 * `App\Jobs\Shipping\GenerateShippingLabelJob` — verifică ȘI capătul de scriere (jobul
 * codifică, nu scrie text), nu doar capătul de citire (Resource-ul traduce).
 */
class JobErrorMessagesTest extends TestCase
{
    use CreatesOrders;

    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();
    }

    private function frenchOwner(string $email = 'fr-owner@throughput.dev'): User
    {
        $owner = $this->makeMember($this->marlin, $email, Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        return $owner;
    }

    public function test_bulk_operation_resource_translates_an_encoded_row_in_french(): void
    {
        $owner = $this->frenchOwner();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $owner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => BulkOperation::STATUS_FAILED,
            'error_message' => JobErrorMessage::encode('job_errors.bulk.initiator_gone'),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->get("/marlin/bulk/{$operation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bulk/Show')
                ->where('operation.errorMessage', 'Le membre qui a lancé cette opération groupée n’est plus disponible.'));
    }

    public function test_report_run_resource_translates_an_encoded_row_in_french(): void
    {
        $owner = $this->frenchOwner();

        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => ReportDefinition::FORMAT_CSV,
            'schedule_frequency' => ReportDefinition::FREQUENCY_NONE,
            'recipients' => [$owner->email],
            'is_active' => true,
            'created_by' => $owner->getKey(),
        ]));

        TenantContext::run($this->marlin, fn () => ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_FAILED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
            'error_message' => JobErrorMessage::encode('job_errors.report.definition_missing'),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->get("/marlin/reports/{$report->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Show')
                ->where('runs.0.errorMessage', 'La définition de ce rapport n’existe plus.'));
    }

    public function test_data_export_request_resource_translates_an_encoded_row_in_french(): void
    {
        $owner = $this->frenchOwner();

        TenantContext::run($this->marlin, function () use ($owner): DataExportRequest {
            $export = new DataExportRequest([
                'status' => DataExportRequest::STATUS_FAILED,
                'requested_at' => now(),
                'completed_at' => now(),
                'error_message' => JobErrorMessage::encode('job_errors.gdpr_export.could_not_start'),
            ]);
            $export->requested_by = $owner->getKey();
            $export->save();

            return $export;
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->get('/marlin/settings/data-export')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/DataExport/Index')
                ->where('requests.0.errorMessage', 'L’export n’a pas pu démarrer. Réessayez.'));
    }

    /**
     * Job REAL (nu un rând scris de mână): `GenerateShippingLabelJob` refuză fără niciun
     * apel extern quand comanda nu mai e deschisă (code review P1, apărare în adâncime — vezi
     * `GenerateShippingLabelJobTest`). Verifică ȘI parametrul AMÂNAT: statusul comenzii
     * (`OrderStatus::Cancelled`, valoare brută „cancelled" în coloană) se traduce abia la
     * randare, „Annulée" — nu „cancelled" brut, nu eticheta engleză „Cancelled".
     */
    public function test_shipment_resource_translates_a_real_jobs_encoded_status_param_in_french(): void
    {
        $owner = $this->frenchOwner();

        [$order, $shipmentId] = TenantContext::run($this->marlin, function () use ($owner): array {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();
            $location = $this->makeDefaultLocation();

            $variant = $this->makeVariant();
            $this->setInventory($variant, $location, onHand: 100);

            $order = new Order([
                'account_id' => $account->getKey(),
                'owner_user_id' => $owner->getKey(),
                'status' => 'draft',
                'currency' => 'USD',
            ]);
            $order->created_by = $owner->getKey();
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

            // Ocolește `CancelOrderAction` deliberat — provoacă direct starea pe care jobul
            // trebuie s-o prindă oricum (identic cu `GenerateShippingLabelJobTest`).
            Order::query()->whereKey($confirmed->getKey())->update(['status' => OrderStatus::Cancelled]);

            return [$confirmed, $shipment->getKey()];
        });
        $this->clearDatabaseTenantContext();

        (new GenerateShippingLabelJob($this->marlin->getKey(), $shipmentId))->handle(new CarrierResolver);

        TenantContext::run($this->marlin, function () use ($shipmentId): void {
            $this->assertSame(Shipment::STATUS_LABEL_FAILED, Shipment::query()->findOrFail($shipmentId)->status);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->get("/marlin/orders/{$order->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Orders/Show')
                ->where(
                    'order.shipments.0.errorMessage',
                    'Cette commande n’est plus ouverte à l’expédition (actuellement Annulée).',
                ));
    }

    /**
     * Job REAL — `PlanBulkOperationJob`, ramura „autorul a dispărut" (§13.2, actor lipsă din
     * `filter_snapshot`). Locale IMPLICIT (`en`, niciun `forceFill` pe utilizator) — proba
     * simetrică lui `test_bulk_operation_resource_translates_an_encoded_row_in_french()`.
     */
    public function test_a_real_plan_bulk_operation_job_failure_renders_through_the_resource(): void
    {
        $owner = $this->makeMember($this->marlin, 'en-owner@throughput.dev', Permissions::OWNER);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $owner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            // Deliberat FĂRĂ `actor_id` — exact scenariul „membrul care a pornit operația
            // nu mai e disponibil" (`PlanBulkOperationJob::plan()`).
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => BulkOperation::STATUS_PENDING,
        ]));
        $this->clearDatabaseTenantContext();

        (new PlanBulkOperationJob($this->marlin->getKey(), $operation->getKey()))->handle();

        $this->actingAs($owner)->get("/marlin/bulk/{$operation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bulk/Show')
                ->where('operation.status', BulkOperation::STATUS_FAILED)
                ->where('operation.errorMessage', 'The member who started this operation is no longer available.'));
    }

    /**
     * Job REAL — `FailStuckBulkOperationsJob` (job de SISTEM, fără `locale` propriu — vezi
     * docblock-ul `FailStuckBulkOperationsJob::MESSAGE_KEY`). Locale implicit `en`.
     */
    public function test_a_real_fail_stuck_bulk_operations_job_failure_renders_through_the_resource(): void
    {
        $owner = $this->makeMember($this->marlin, 'en-owner-2@throughput.dev', Permissions::OWNER);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $owner->getKey(),
            'resource_type' => 'accounts',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => BulkOperation::STATUS_RUNNING,
        ]));
        TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->whereKey($operation->getKey())->update(['updated_at' => now()->subMinutes(20)]),
        );
        $this->clearDatabaseTenantContext();

        (new FailStuckBulkOperationsJob)->handle();

        $this->actingAs($owner)->get("/marlin/bulk/{$operation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bulk/Show')
                ->where('operation.status', BulkOperation::STATUS_FAILED)
                ->where('operation.errorMessage', 'This operation could not start. Please try again.'));
    }
}
