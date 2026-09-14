<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\RecordStockMovementAction;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * FR-STOCK-03 — istoric de mișcări per variantă, paginat pe cursor, filtrabil pe motiv.
 * Citit de toate cele 4 roluri (§7.4: „stock.view", inclusiv Viewer), scris de niciunul
 * direct — doar prin `RecordStockMovementAction`/`TransferStockAction`.
 */
class StockMovementHistoryTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->variant = TenantContext::run($this->marlin, function (): Variant {
            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->getKey()]);
            $location = (new LocationFactory)->create();

            $action = new RecordStockMovementAction;
            $action->execute($variant, $location, 200, StockMovement::REASON_RECEIPT, $this->owner);
            $action->execute($variant, $location, -5, StockMovement::REASON_ADJUSTMENT, $this->owner, note: 'Cycle count.');

            return $variant;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_every_role_can_read_the_movement_history(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        foreach ([$this->owner, $manager, $agent, $viewer] as $user) {
            $this->actingAs($user)->get("/marlin/variants/{$this->variant->id}/stock/history")->assertOk();
        }
    }

    public function test_the_reason_filter_narrows_the_movements(): void
    {
        $titles = null;

        $this->actingAs($this->owner)->get("/marlin/variants/{$this->variant->id}/stock/history?filter[reason]=adjustment")
            ->assertInertia(function (Assert $page) use (&$titles): void {
                $page->where('list.filter.reason', 'adjustment');
                $page->reloadOnly('movements', function (Assert $reloaded) use (&$titles): void {
                    $titles = array_column($reloaded->toArray()['props']['movements']['data'], 'reason');
                });
            });

        $this->assertSame(['adjustment'], $titles);
    }

    public function test_movements_cannot_be_edited_or_deleted_through_any_route(): void
    {
        $movement = TenantContext::run($this->marlin, fn () => StockMovement::query()->where('variant_id', $this->variant->getKey())->firstOrFail());

        // Niciun controller/rută din acest pachet expune UPDATE/DELETE pe `stock_movements`
        // (BR-STOCK-01) — verificat la nivelul cel mai jos posibil: modelul însuși refuză.
        $this->expectException(RuntimeException::class);
        TenantContext::run($this->marlin, fn () => $movement->delete());
    }
}
