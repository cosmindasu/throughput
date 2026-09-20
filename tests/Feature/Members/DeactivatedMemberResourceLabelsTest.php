<?php

namespace Tests\Feature\Members;

use App\Http\Resources\DealStageEventResource;
use App\Http\Resources\Imports\ImportResource;
use App\Http\Resources\Reports\ReportDefinitionResource;
use App\Http\Resources\Stock\StockMovementResource;
use App\Models\DealStageEvent;
use App\Models\Import;
use App\Models\Membership;
use App\Models\ReportDefinition;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * FR-TEN-04, a doua jumătate — „ORICE referință de owner/creator/actor către un membru cu
 * `status = deactivated` afișează un placeholder «(deactivated)»".
 *
 * `DeactivatedMemberPlaceholderTest` (Faza 3) acoperă `AccountResource` și feed-ul de
 * activitate. Lotul de față a găsit patru `Resource`-uri cu referință de actor care NU
 * treceau prin `DeactivatedMemberNames` — construite de faze diferite, fiecare fără să
 * știe de regulă:
 *
 *   - `ImportResource::createdBy` (Faza 4, importul CSV)
 *   - `ReportDefinitionResource::createdBy` (Faza 4, rapoarte programate)
 *   - `StockMovementResource::createdBy` (Faza 3, istoricul de stoc)
 *   - `DealStageEventResource::changedBy` (Faza 2, istoricul de etape — chiar tiparul din
 *     Gherkin-ul US-TEN-03: „Jane Doe (deactivated) created this order on [dată]")
 *
 * Verificat DIRECT pe `Resource`, nu prin HTTP: exact acolo e regula (plan §11 — „la
 * nivelul `Resource`-urilor, nu per componentă React"), iar un test HTTP per ecran ar fi
 * cerut patru seturi de fixture nerelevante (variantă + locație, deal + etapă, fișier de
 * import, raport + saved view) pentru a verifica o singură linie din fiecare.
 */
class DeactivatedMemberResourceLabelsTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();

        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, fn () => Membership::query()
            ->where('user_id', $this->jane->getKey())
            ->update(['status' => Membership::STATUS_DEACTIVATED, 'deactivated_at' => now()]));

        $this->clearDatabaseTenantContext();
    }

    public function test_the_import_list_marks_a_deactivated_author(): void
    {
        $payload = $this->inTenant(function (): array {
            $import = new Import(['resource_type' => 'accounts', 'original_filename' => 'accounts.csv', 'status' => 'completed']);
            $import->setRelation('createdBy', $this->jane);

            return (new ImportResource($import))->toArray(Request::create('/'));
        });

        $this->assertSame('Jane (deactivated)', $payload['createdBy']['name']);
    }

    public function test_a_scheduled_report_marks_a_deactivated_author(): void
    {
        $payload = $this->inTenant(function (): array {
            $report = new ReportDefinition([
                'name' => 'Monthly sales',
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
                'format' => 'csv',
                'recipients' => ['ops@example.com'],
                'is_active' => true,
            ]);
            $report->setRelation('createdBy', $this->jane);
            $report->setRelation('savedView', null);
            $report->setRelation('latestRun', null);

            return (new ReportDefinitionResource($report))->toArray(Request::create('/'));
        });

        $this->assertSame('Jane (deactivated)', $payload['createdBy']);
    }

    public function test_the_stock_history_marks_a_deactivated_author(): void
    {
        $payload = $this->inTenant(function (): array {
            $movement = new StockMovement(['delta' => -3, 'reason' => 'adjustment']);
            $movement->setRelation('createdBy', $this->jane);
            $movement->setRelation('location', null);

            return (new StockMovementResource($movement))->toArray(Request::create('/'));
        });

        $this->assertSame('Jane (deactivated)', $payload['createdBy']['name']);
    }

    public function test_the_deal_stage_history_marks_a_deactivated_actor(): void
    {
        $payload = $this->inTenant(function (): array {
            $event = new DealStageEvent(['changed_at' => now()]);
            $event->setRelation('changedBy', $this->jane);

            return (new DealStageEventResource($event))->toArray(Request::create('/'));
        });

        $this->assertSame('Jane (deactivated)', $payload['changedBy']['name']);
    }

    /** Contra-proba: un membru ACTIV nu capătă niciodată eticheta. */
    public function test_an_active_member_is_never_labelled(): void
    {
        $payload = $this->inTenant(function (): array {
            $movement = new StockMovement(['delta' => 5, 'reason' => 'adjustment']);
            $movement->setRelation('createdBy', $this->owner);
            $movement->setRelation('location', null);

            return (new StockMovementResource($movement))->toArray(Request::create('/'));
        });

        $this->assertSame($this->owner->name, $payload['createdBy']['name']);
    }

    /**
     * `DeactivatedMemberNames` citește setul de id-uri prin `app('tenant')` — binding-ul
     * pe care `ResolveWorkspace` îl face pe fiecare cerere. Aici îl punem explicit, fiindcă
     * nu trecem printr-o cerere HTTP.
     */
    private function inTenant(callable $callback): array
    {
        $result = TenantContext::run($this->marlin, function () use ($callback): array {
            app()->instance('tenant', $this->marlin);

            return $callback();
        });

        $this->clearDatabaseTenantContext();

        return $result;
    }
}
