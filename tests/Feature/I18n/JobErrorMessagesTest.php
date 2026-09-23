<?php

namespace Tests\Feature\I18n;

use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Shipments\CreateShipmentAction;
use App\Enums\OrderStatus;
use App\Jobs\Bulk\PlanBulkOperationJob;
use App\Jobs\Reports\GenerateReportJob;
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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\Concerns\CreatesOrders;
use Tests\TestCase;
use Throwable;

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
 *
 * P2 (lot i18n, „error_message brut în catch-all-uri") — mai jos, DOUĂ teste suplimentare
 * pentru ramurile GENERICE (`catch (Throwable $e)`, excepție NEAȘTEPTATĂ, nu una din cele
 * deja catalogate mai sus) ale `PlanBulkOperationJob::handle()` și `GenerateReportJob::handle()`:
 * nici acelea nu mai scriu `getMessage()` brut pe coloană. Perechea pentru
 * `App\Jobs\Exports\ExportListJob` trăiește în `tests/Feature/Exports/OrderExportTest.php`
 * (job REAL, dar `App\Http\Resources\Exports\ExportResource` nu expune deloc `errorMessage`
 * — un gol preexistent, în afara feliei acestui lot — deci acolo verificarea se oprește la
 * `JobErrorMessage::render()`, nu la o pagină reală).
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
     * P2 (lot i18n, „error_message brut în catch-all-uri") — ramura GENERICĂ
     * (`catch (Throwable $e)`) din `PlanBulkOperationJob::handle()`, distinctă de
     * `initiator_gone` de mai sus: aici, un `resource_type` FĂRĂ operație de SCRIERE
     * înregistrată (`BulkWritableResources::resolve()` aruncă `InvalidArgumentException`
     * ÎNAINTEA verificării actorului). Coloana nu mai poartă `getMessage()` brut (poate purta
     * SQL/căi interne) — verifică cheia codificată, randarea REALĂ în ambele limbi (un Owner
     * poate vedea orice operație a propriei lui persoane — vezi `BulkOperationPolicy::view()`,
     * de-asta operația își schimbă `user_id` între cele două cereri) și că excepția
     * originală tot ajunge la `report()`.
     */
    public function test_an_unexpected_plan_bulk_operation_job_failure_is_encoded_and_renders_in_both_locales(): void
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            private array $reported;

            public function __construct(array &$reported)
            {
                $this->reported = &$reported;
            }

            public function report(Throwable $e)
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e)
            {
                return true;
            }

            public function render($request, Throwable $e) {}

            public function renderForConsole($output, Throwable $e) {}
        });

        $enOwner = $this->makeMember($this->marlin, 'en-owner-unexpected@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $enOwner->getKey(),
            // Fără operație de SCRIERE înregistrată (`BulkWritableResources::map()`) —
            // `resolve()` aruncă ÎNAINTE de orice altceva din `plan()`.
            'resource_type' => 'invoices',
            'action' => BulkChunkActions::REASSIGN_OWNER,
            'filter_snapshot' => ['filter' => []],
            'total_rows' => 3,
            'status' => BulkOperation::STATUS_PENDING,
        ]));
        $this->clearDatabaseTenantContext();

        (new PlanBulkOperationJob($this->marlin->getKey(), $operation->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->clearDatabaseTenantContext();

        $this->assertSame(BulkOperation::STATUS_FAILED, $fresh->status);
        $this->assertSame(
            JobErrorMessage::encode('job_errors.bulk.unexpected'),
            $fresh->error_message,
            'Coloana trebuia să poarte cheia codificată, nu getMessage() brut al InvalidArgumentException.',
        );

        $this->actingAs($enOwner)->get("/marlin/bulk/{$operation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bulk/Show')
                ->where('operation.errorMessage', 'This operation failed due to an unexpected error. Try again or contact support if it keeps happening.'));

        $frOwner = $this->frenchOwner('fr-owner-unexpected@throughput.dev');
        TenantContext::run($this->marlin, fn () => BulkOperation::query()->whereKey($operation->getKey())->update(['user_id' => $frOwner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($frOwner)->get("/marlin/bulk/{$operation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bulk/Show')
                ->where('operation.errorMessage', 'Cette opération groupée a échoué en raison d’une erreur inattendue. Réessayez ou contactez le support si le problème persiste.'));

        $this->assertCount(1, $reported, 'Excepția neașteptată trebuia raportată prin report(), nu doar scrisă pe coloană.');
        $this->assertInstanceOf(InvalidArgumentException::class, $reported[0]);
    }

    /**
     * P2 (lot i18n, „error_message brut în catch-all-uri") — ramura „orice altă Throwable"
     * a ternarului din `GenerateReportJob::handle()` (aici, `saved_view_export` fără
     * `saved_view_id` valid — `RuntimeException` PROPRIE a jobului, distinctă de
     * `ReportRowCapExceededException`, care păstrează cheia ei specifică). Un Owner poate
     * vedea ORICE raport al tenantului (`ReportDefinitionPolicy::view()` nu verifică
     * `created_by`), deci ACELAȘI rând se verifică pentru amândoi, fără o a doua rulare.
     */
    public function test_an_unexpected_generate_report_job_failure_is_encoded_and_renders_in_both_locales(): void
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            private array $reported;

            public function __construct(array &$reported)
            {
                $this->reported = &$reported;
            }

            public function report(Throwable $e)
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e)
            {
                return true;
            }

            public function render($request, Throwable $e) {}

            public function renderForConsole($output, Throwable $e) {}
        });

        $enOwner = $this->makeMember($this->marlin, 'en-owner-report-unexpected@throughput.dev', Permissions::OWNER);
        $frOwner = $this->frenchOwner('fr-owner-report-unexpected@throughput.dev');
        $this->clearDatabaseTenantContext();

        // Sursă `saved_view_export` fără o vedere validă — vezi `GenerateReportJobTest`
        // (`test_a_failed_generation_does_not_dispatch_delivery_or_send_email`), care
        // forțează exact aceeași `RuntimeException`.
        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
            'saved_view_id' => null,
            'name' => 'Broken report',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => [$enOwner->email],
            'is_active' => true,
            'created_by' => $enOwner->getKey(),
        ]));

        $run = TenantContext::run($this->marlin, fn () => ReportRun::query()->create([
            'report_definition_id' => $report->getKey(),
            'status' => ReportRun::STATUS_QUEUED,
            'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
        ]));
        $this->clearDatabaseTenantContext();

        (new GenerateReportJob($this->marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($this->marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_FAILED, $fresh->status);
        $this->assertSame(
            JobErrorMessage::encode('job_errors.report.unexpected'),
            $fresh->error_message,
            'Coloana trebuia să poarte cheia codificată, nu getMessage() brut al RuntimeException.',
        );

        $this->actingAs($enOwner)->get("/marlin/reports/{$report->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Show')
                ->where('runs.0.errorMessage', 'This report failed due to an unexpected error. Try again or contact support if it keeps happening.'));

        $this->actingAs($frOwner)->get("/marlin/reports/{$report->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Show')
                ->where('runs.0.errorMessage', 'Ce rapport a échoué en raison d’une erreur inattendue. Réessayez ou contactez le support si le problème persiste.'));

        $this->assertCount(1, $reported, 'Excepția neașteptată trebuia raportată prin report(), nu doar scrisă pe coloană.');
        $this->assertInstanceOf(\RuntimeException::class, $reported[0]);
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
