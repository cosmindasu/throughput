<?php

namespace Tests\Feature\Bulk;

use App\Jobs\Bulk\ProcessBulkChunkJob;
use App\Models\BulkOperation;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Produse — §13.5 (lotul E, valul „bulk"): preț în masă (procent/sumă, +/-) pe variantele
 * produselor selectate și activare/dezactivare în masă. Doar Owner/Manager
 * (`ProductPolicy::bulkWrite()`); Agent/Viewer n-au `products.edit`.
 */
class ProductBulkOperationsTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    // ── Preț în masă ─────────────────────────────────────────────────────────────────

    public function test_owner_can_increase_price_by_percent_on_every_variant_of_selected_products(): void
    {
        [$productId, $variantIds] = TenantContext::run($this->marlin, function (): array {
            $product = $this->makeProduct();
            $v1 = $this->makeVariant($product, 'SKU-A', 100.00);
            $v2 = $this->makeVariant($product, 'SKU-B', 50.00);

            return [$product->getKey(), [$v1->getKey(), $v2->getKey()]];
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/products/bulk/update-price', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'mode' => 'percent',
            'direction' => 'increase',
            'amount' => 10,
        ]);

        $operation = $this->soleOperation('update_price');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());

        $this->drainBulkQueue();

        $prices = TenantContext::run($this->marlin, fn () => Variant::query()->whereIn('id', $variantIds)->orderBy('sku')->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame([110.00, 55.00], $prices);
    }

    public function test_a_fixed_decrease_never_takes_the_price_below_zero(): void
    {
        [$productId, $variantId] = TenantContext::run($this->marlin, function (): array {
            $product = $this->makeProduct();
            $variant = $this->makeVariant($product, 'SKU-A', 5.00);

            return [$product->getKey(), $variant->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/products/bulk/update-price', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'mode' => 'fixed',
            'direction' => 'decrease',
            'amount' => 50,
        ])->assertRedirect();

        $this->drainBulkQueue();

        $price = TenantContext::run($this->marlin, fn () => (float) Variant::query()->find($variantId)->price);
        $this->assertSame(0.0, $price);
    }

    /**
     * P3-002 (code review) — o creștere fixă mare nu trebuie să depășească
     * `decimal(10,2)` (10 cifre, 2 zecimale): `LEAST(..., 99999999.99)`, ca o astfel de
     * cerere să nu arunce o eroare SQL de „numeric field overflow" care ar lăsa chunk-ul
     * eșuat tăcut, fără niciun rând schimbat.
     */
    public function test_a_large_fixed_increase_is_clamped_to_the_column_maximum(): void
    {
        [$productId, $variantId] = TenantContext::run($this->marlin, function (): array {
            $product = $this->makeProduct();
            $variant = $this->makeVariant($product, 'SKU-A', 99999990.00);

            return [$product->getKey(), $variant->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/products/bulk/update-price', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'mode' => 'fixed',
            'direction' => 'increase',
            'amount' => 999999,
        ])->assertRedirect();

        $this->drainBulkQueue();

        $operation = $this->soleOperation('update_price');
        $this->assertSame(BulkOperation::STATUS_COMPLETED, TenantContext::run($this->marlin, fn () => $operation->fresh()->status));

        $price = TenantContext::run($this->marlin, fn () => (float) Variant::query()->find($variantId)->price);
        $this->assertSame(99999999.99, $price);
    }

    /**
     * P2-001 (code review, decizia proprietarului) — regresie pe scenariul EXACT găsit cu
     * tinker: operația A aplică +10% și se comite; operația B aplică +10% pe același
     * produs; jobul lui A e redelivrat (crash între commit și ack, `retry_after`) — prețul
     * trebuie să rămână cel de după B (121.00), NU 133.10 (dacă A s-ar fi reaplicat).
     * Prin jobul REAL (`ProcessBulkChunkJob`), nu prin `UpdatePriceAction::apply()` direct
     * — garanția de idempotență trăiește acum la nivel de chunk (`bulk_operation_chunks`),
     * nu pe un marcaj de pe variantă.
     */
    public function test_a_chunk_retried_after_a_later_operation_does_not_reapply_the_percent(): void
    {
        [$productId, $variantId] = TenantContext::run($this->marlin, function (): array {
            $product = $this->makeProduct();
            $variant = $this->makeVariant($product, 'SKU-A', 100.00);

            return [$product->getKey(), $variant->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $payload = ['mode' => 'percent', 'direction' => 'increase', 'amount' => 10];
        // `bulk_operation_chunks.bulk_operation_id` are FK către `bulk_operations`
        // (migrația `2026_09_14_160000`) — rânduri REALE, nu id-uri arbitrare.
        $operationA = $this->makeOperation('products', BulkChunkActions::UPDATE_PRICE, $payload);
        $operationB = $this->makeOperation('products', BulkChunkActions::UPDATE_PRICE, $payload);

        $this->runChunk($operationA, 'products', BulkChunkActions::UPDATE_PRICE, [$productId], $payload, chunk: 0);
        $this->assertSame(110.0, $this->currentPrice($variantId));

        $this->runChunk($operationB, 'products', BulkChunkActions::UPDATE_PRICE, [$productId], $payload, chunk: 0);
        $this->assertSame(121.0, $this->currentPrice($variantId));

        // Redelivrarea chunk-ului lui A (ACELAȘI operationId, ACELAȘI index de chunk).
        $this->runChunk($operationA, 'products', BulkChunkActions::UPDATE_PRICE, [$productId], $payload, chunk: 0);
        $this->assertSame(121.0, $this->currentPrice($variantId), 'Chunk-ul lui A redelivrat nu trebuia să mai schimbe prețul.');
    }

    public function test_agent_cannot_update_price(): void
    {
        $productId = TenantContext::run($this->marlin, fn () => $this->makeProduct()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->post('/marlin/products/bulk/update-price', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'mode' => 'percent',
            'direction' => 'increase',
            'amount' => 10,
        ])->assertForbidden();
    }

    public function test_viewer_cannot_update_price(): void
    {
        $productId = TenantContext::run($this->marlin, fn () => $this->makeProduct()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)->post('/marlin/products/bulk/update-price', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'mode' => 'percent',
            'direction' => 'increase',
            'amount' => 10,
        ])->assertForbidden();
    }

    // ── Activare/dezactivare în masă ─────────────────────────────────────────────────

    public function test_manager_can_deactivate_selected_products(): void
    {
        $ids = TenantContext::run($this->marlin, fn () => [
            $this->makeProduct('Product A')->getKey(),
            $this->makeProduct('Product B')->getKey(),
        ]);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->manager)->post('/marlin/products/bulk/set-active', [
            'selectAllMatching' => false,
            'ids' => $ids,
            'active' => false,
        ]);

        $operation = $this->soleOperation('set_active');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());

        $this->drainBulkQueue();

        $states = TenantContext::run($this->marlin, fn () => Product::query()->whereIn('id', $ids)->pluck('is_active')->all());
        $this->assertSame([false, false], $states);
    }

    /**
     * Idempotență de chunk (code review „P2-001") — prin jobul real, nu doar prin
     * `apply()`: reîncercarea ACELUIAȘI chunk (același `bulk_operation_id` + index) nu
     * mai atinge rândul a doua oară.
     */
    public function test_running_the_same_toggle_chunk_twice_through_the_real_job_only_toggles_once(): void
    {
        $productId = TenantContext::run($this->marlin, fn () => $this->makeProduct()->getKey());
        $this->clearDatabaseTenantContext();

        $payload = ['active' => false];
        $operationId = $this->makeOperation('products', BulkChunkActions::SET_ACTIVE, $payload);

        $this->runChunk($operationId, 'products', BulkChunkActions::SET_ACTIVE, [$productId], $payload, chunk: 0);
        $this->assertSame([false], TenantContext::run($this->marlin, fn () => Product::query()->whereKey($productId)->pluck('is_active')->all()));

        // Reactivăm manual, direct în bază, ca să distingem „chunk-ul a rulat a doua oară"
        // de „chunk-ul a fost sărit" — dacă a doua rulare ar chema `apply()` din nou, ar
        // rescrie `is_active = false` peste reactivarea manuală de mai jos.
        TenantContext::run($this->marlin, fn () => Product::query()->whereKey($productId)->update(['is_active' => true]));

        $this->runChunk($operationId, 'products', BulkChunkActions::SET_ACTIVE, [$productId], $payload, chunk: 0);
        $this->assertSame(
            [true],
            TenantContext::run($this->marlin, fn () => Product::query()->whereKey($productId)->pluck('is_active')->all()),
            'Chunk-ul redelivrat nu trebuia să mai cheme apply() — reactivarea manuală trebuia să rămână neatinsă.',
        );
    }

    public function test_agent_cannot_toggle_product_active_state(): void
    {
        $productId = TenantContext::run($this->marlin, fn () => $this->makeProduct()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)->post('/marlin/products/bulk/set-active', [
            'selectAllMatching' => false,
            'ids' => [$productId],
            'active' => false,
        ])->assertForbidden();
    }

    private function makeProduct(string $name = 'Test Product'): Product
    {
        return Product::query()->create(['name' => $name, 'is_active' => true]);
    }

    private function makeVariant(Product $product, string $sku, float $price): Variant
    {
        return Variant::query()->create([
            'product_id' => $product->getKey(),
            'sku' => $sku,
            'attributes' => [],
            'price' => $price,
            'cost' => round($price * 0.6, 2),
            'is_active' => true,
        ]);
    }

    private function currentPrice(string $variantId): float
    {
        return TenantContext::run($this->marlin, fn () => (float) Variant::query()->find($variantId)->price);
    }

    /**
     * `bulk_operation_chunks.bulk_operation_id` are FK către `bulk_operations`
     * (migrația `2026_09_14_160000`) — testele care dispecerizează `ProcessBulkChunkJob`
     * direct (fără planificator) au nevoie de un rând PĂRINTE real, nu doar de un ULID.
     *
     * @param  array<string, mixed>  $payload
     */
    private function makeOperation(string $resourceType, string $action, array $payload): string
    {
        return TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => $resourceType,
            'action' => $action,
            'filter_snapshot' => ['ids' => null, 'action_payload' => $payload],
            'total_rows' => 1,
            'status' => BulkOperation::STATUS_RUNNING,
        ])->getKey());
    }

    private function soleOperation(string $action): BulkOperation
    {
        return TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', 'products')->where('action', $action)->firstOrFail(),
        );
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $payload
     */
    private function runChunk(string $operationId, string $resourceType, string $action, array $ids, array $payload, int $chunk): void
    {
        ProcessBulkChunkJob::dispatch($this->marlin->getKey(), $operationId, $resourceType, $action, $ids, $payload, $chunk)->onQueue('bulk');
        $this->drainBulkQueue();
    }

    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
