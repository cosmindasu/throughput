<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RecordStockMovementAction;
use App\Models\InventoryLevel;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Tests\TestCase;

/**
 * FR-STOCK-01, §10.4 — `stock:reconcile` recalculează `on_hand` din `stock_movements` și
 * raportează divergențele, fără să corecteze. Rulează per tenant, cu `TenantContext::run()`
 * (comandă de sistem, ADR-014 pct. 4) — al treilea test verifică explicit izolarea: o
 * divergență într-un tenant nu poate „ascunde" sau „inventa" una în celălalt.
 */
class StockReconcileTest extends TestCase
{
    public function test_it_reports_zero_divergences_on_consistent_data(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($tenant, function () use ($owner): void {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();

            (new RecordStockMovementAction)->execute($variant, $location, 200, StockMovement::REASON_RECEIPT, $owner);
        });
        $this->clearDatabaseTenantContext();

        $this->artisan('stock:reconcile', ['--tenant' => 'marlin'])
            ->assertExitCode(0);
    }

    public function test_it_reports_a_divergence_when_on_hand_is_written_without_a_movement(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($tenant, function () use ($owner): void {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();

            (new RecordStockMovementAction)->execute($variant, $location, 200, StockMovement::REASON_RECEIPT, $owner);

            // Simulează exact bug-ul pe care reconcilierea există să-l prindă: `on_hand`
            // scris direct, fără o mișcare în spate (BR-STOCK-02 încălcată în afara
            // acțiunii — ceva a scris proiecția în doi pași).
            InventoryLevel::query()->where('variant_id', $variant->getKey())->update(['on_hand' => 9999]);
        });
        $this->clearDatabaseTenantContext();

        $this->artisan('stock:reconcile', ['--tenant' => 'marlin'])
            ->assertExitCode(1);

        // Nu corectează — valoarea tamponată rămâne exact cum a fost lăsată (FR-STOCK-01).
        $onHand = TenantContext::run($tenant, fn () => InventoryLevel::query()->value('on_hand'));
        $this->assertSame(9999, $onHand);
    }

    public function test_a_divergence_in_one_tenant_does_not_affect_the_report_of_another(): void
    {
        $clean = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cleanOwner = $this->makeMember($clean, 'demo.owner@marlin.dev', Permissions::OWNER);

        $dirty = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $dirtyOwner = $this->makeMember($dirty, 'demo.owner@cascade.dev', Permissions::OWNER);

        TenantContext::run($clean, function () use ($cleanOwner): void {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();
            (new RecordStockMovementAction)->execute($variant, $location, 50, StockMovement::REASON_RECEIPT, $cleanOwner);
        });

        TenantContext::run($dirty, function () use ($dirtyOwner): void {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();
            (new RecordStockMovementAction)->execute($variant, $location, 50, StockMovement::REASON_RECEIPT, $dirtyOwner);
            InventoryLevel::query()->where('variant_id', $variant->getKey())->update(['on_hand' => 1]);
        });
        $this->clearDatabaseTenantContext();

        // Fiecare tenant verificat separat rămâne consistent cu propriile lui date —
        // izolarea RLS/global scope garantează deja asta, dar comanda de sistem citește
        // pe conexiunea normală a aplicației, deci testul o verifică explicit aici.
        $this->artisan('stock:reconcile', ['--tenant' => 'marlin'])->assertExitCode(0);
        $this->artisan('stock:reconcile', ['--tenant' => 'cascade'])->assertExitCode(1);

        // Rulată fără `--tenant` (toți tenanții), comanda tot raportează divergența —
        // nu se pierde în agregare pe mai mulți tenanți.
        $this->artisan('stock:reconcile')->assertExitCode(1);
    }
}
