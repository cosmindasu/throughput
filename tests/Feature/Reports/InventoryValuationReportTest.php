<?php

namespace Tests\Feature\Reports;

use App\Models\ReportDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use App\Support\Reports\InventoryValuationReport;
use Database\Factories\InventoryLevelFactory;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Tests\TestCase;

/**
 * „Inventory Valuation" (specs.md §16.3) — `on_hand × variants.cost` per locație/categorie,
 * cifre verificabile pe date semănate exact.
 */
class InventoryValuationReportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    private function seedFixture(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $main = (new LocationFactory)->create(['name' => 'Main Warehouse']);
            $overflow = (new LocationFactory)->overflow()->create(['name' => 'Overflow Storage']);

            $fastenerProduct = (new ProductFactory)->create(['category' => 'Fasteners']);
            $toolProduct = (new ProductFactory)->create(['category' => 'Tools']);

            $fastenerVariant = (new VariantFactory)->create(['product_id' => $fastenerProduct->id, 'cost' => 2.00]);
            $toolVariant = (new VariantFactory)->create(['product_id' => $toolProduct->id, 'cost' => 5.50]);

            (new InventoryLevelFactory)->create(['variant_id' => $fastenerVariant->id, 'location_id' => $main->id, 'on_hand' => 100]);
            (new InventoryLevelFactory)->create(['variant_id' => $fastenerVariant->id, 'location_id' => $overflow->id, 'on_hand' => 50]);
            (new InventoryLevelFactory)->create(['variant_id' => $toolVariant->id, 'location_id' => $main->id, 'on_hand' => 10]);
        });
    }

    public function test_it_computes_total_value_per_location_and_category(): void
    {
        $this->seedFixture();

        $rows = TenantContext::run($this->marlin, fn () => (new InventoryValuationReport)->rows());
        $this->clearDatabaseTenantContext();

        $byKey = collect($rows)->keyBy(fn (array $row) => $row[0].'|'.$row[1]);

        $mainFasteners = $byKey->get('Main Warehouse|Fasteners');
        $this->assertSame(100, $mainFasteners[2]);
        $this->assertSame(200.0, $mainFasteners[3]);

        $mainTools = $byKey->get('Main Warehouse|Tools');
        $this->assertSame(10, $mainTools[2]);
        $this->assertSame(55.0, $mainTools[3]);

        $overflowFasteners = $byKey->get('Overflow Storage|Fasteners');
        $this->assertSame(50, $overflowFasteners[2]);
        $this->assertSame(100.0, $overflowFasteners[3]);

        $this->assertCount(3, $rows);
    }

    /**
     * DECIZIE, rafinată la review (raportul lotului K) — `variants.cost` rămâne
     * NEREDACTAT în FIȘIER (descărcat/trimis pe email): un singur fișier per rulare,
     * trimis identic tuturor destinatarilor; alegerea rămâne responsabilitatea
     * Owner/Manager (singurii cu `reports.manage`). Limitare asumată, nu rezolvată aici.
     *
     * Previzualizarea SINCRONĂ din `Reports/Show.tsx`, în schimb, e ascunsă pentru cine
     * n-are `Permissions::canViewCost()` (fix P1, review): un Agent destinatar poate
     * DESCHIDE pagina (§7.4 — „R" pe rapoartele unde e destinatar), dar nu vede tabelul
     * `on_hand × cost` randat direct în aplicație.
     */
    public function test_an_agent_recipient_can_open_the_page_but_the_preview_is_hidden_for_cost(): void
    {
        $this->seedFixture();
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.agent@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($agent)->get("/marlin/reports/{$report->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->where('builtInPreview.hiddenForCost', true)
            ->where('builtInPreview.totalRows', 0)
            ->where('builtInPreview.rows', []));
    }

    public function test_an_owner_sees_the_full_preview_on_the_same_report(): void
    {
        $this->seedFixture();

        $report = TenantContext::run($this->marlin, fn () => ReportDefinition::forceCreate([
            'report_type' => ReportDefinition::TYPE_INVENTORY_VALUATION,
            'name' => 'Inventory Valuation',
            'format' => 'csv',
            'schedule_frequency' => 'none',
            'recipients' => ['demo.owner@throughput.dev'],
            'is_active' => true,
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/reports/{$report->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->where('builtInPreview.hiddenForCost', false)
            ->where('builtInPreview.totalRows', 3));
    }
}
