<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Pasul 4 — Commit (§14.1 pct. 4): ingestie PARȚIALĂ, niciodată all-or-nothing. Rândurile
 * valide se importă, cele invalide rămân raportate — și BR-IMP-01 (`raw_data` păstrat
 * inclusiv după finalizare).
 */
class CommitImportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_commit_creates_entities_for_valid_rows_and_skips_invalid_ones(): void
    {
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-VALID-1,Widget One,9.99,4.00',
            'SKU-VALID-2,Widget Two,19.99,8.00',
            'SKU-INVALID,Widget Three,N/A,4.00',
        ]).PHP_EOL;

        $import = $this->fullyValidatedImport($content);

        $this->assertSame(2, TenantContext::run($this->marlin, fn () => $import->fresh()->valid_rows));
        $this->assertSame(1, TenantContext::run($this->marlin, fn () => $import->fresh()->error_rows));

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit")->assertRedirect();
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $fresh->status);
        $this->assertNotNull($fresh->completed_at);

        $variants = TenantContext::run($this->marlin, fn () => Variant::query()->pluck('sku')->sort()->values()->all());
        $this->assertSame(['SKU-VALID-1', 'SKU-VALID-2'], $variants);

        $rows = TenantContext::run($this->marlin, fn () => ImportRow::query()->where('import_id', $import->getKey())->orderBy('row_number')->get());
        $byRowNumber = $rows->keyBy('row_number');

        $this->assertSame(ImportRow::STATUS_IMPORTED, $byRowNumber[2]->status);
        $this->assertNotNull($byRowNumber[2]->created_entity_id);

        $this->assertSame(ImportRow::STATUS_IMPORTED, $byRowNumber[3]->status);
        $this->assertNotNull($byRowNumber[3]->created_entity_id);

        // Rândul invalid rămâne invalid — NU intră în commit, dar `raw_data` supraviețuiește
        // finalizării (BR-IMP-01), inclusiv pentru rândul eșuat.
        $this->assertSame(ImportRow::STATUS_INVALID, $byRowNumber[4]->status);
        $this->assertNull($byRowNumber[4]->created_entity_id);
        $this->assertSame('N/A', $byRowNumber[4]->raw_data['Price']);
    }

    public function test_a_fully_valid_import_completes_without_errors(): void
    {
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-A,Widget A,9.99,4.00',
        ]).PHP_EOL;

        $import = $this->fullyValidatedImport($content);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit")->assertRedirect();
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_COMPLETED, $fresh->status);
    }

    /**
     * Rezistență la o cursă rară (§tenancy.md, „un create() într-un catch pentru unique
     * violation nu salvează nimic: orice eroare abortează tranzacția"): un SKU marcat
     * `valid` la proba uscată e creat concurent ÎNAINTE de commit (simulat direct, nu printr-
     * un al doilea import — §22.5 blochează deja concurența reală). `CommitImportJob`
     * scrie fiecare rând într-un SAVEPOINT propriu — un eșec de scriere pe UN rând nu
     * dărâmă restul chunk-ului.
     */
    public function test_a_row_that_fails_to_write_does_not_abort_the_rest_of_the_chunk(): void
    {
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-BEFORE,Widget Before,9.99,4.00',
            'SKU-RACE,Widget Race,19.99,8.00',
            'SKU-AFTER,Widget After,29.99,12.00',
        ]).PHP_EOL;

        $import = $this->fullyValidatedImport($content);
        $this->assertSame(3, TenantContext::run($this->marlin, fn () => $import->fresh()->valid_rows));

        // Cursă simulată: „SKU-RACE" ajunge în bază ÎNTRE dry-run și commit.
        TenantContext::run($this->marlin, function (): void {
            $product = Product::create(['name' => 'Raced product', 'unit_of_measure' => 'each', 'is_active' => true]);
            Variant::create(['product_id' => $product->getKey(), 'sku' => 'SKU-RACE', 'price' => 1, 'cost' => 1, 'is_active' => true]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit")->assertRedirect();
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $fresh->status);

        // Rândurile DINAINTE și DUPĂ cel eșuat, ÎN ACELAȘI chunk, tot s-au importat —
        // savepoint-ul a izolat DOAR rândul care a eșuat.
        $importedSkus = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('status', ImportRow::STATUS_IMPORTED)->get()
                ->map(fn (ImportRow $row) => $row->raw_data['SKU'])->sort()->values()->all(),
        );
        $this->assertSame(['SKU-AFTER', 'SKU-BEFORE'], $importedSkus);

        $failedRow = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('status', ImportRow::STATUS_INVALID)->sole(),
        );
        $this->assertSame('SKU-RACE', $failedRow->raw_data['SKU']);
    }

    /**
     * P2, review general — N+1 la commit: `VariantImportResource::prepareChunk()` rezolvă
     * produsul-părinte O SINGURĂ DATĂ per nume distinct din chunk, cu „write-through" pentru
     * produsele create ÎN ACELAȘI chunk. Verificat prin CORECTITUDINE (un singur produs
     * „Shared Product" pentru trei variante, nu trei produse duplicate), nu prin numărarea
     * interogărilor — efectul observabil e identic, indiferent de mecanism.
     */
    public function test_multiple_variants_sharing_a_product_name_create_only_one_product(): void
    {
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-1,Shared Product,9.99,4.00',
            'SKU-2,Shared Product,19.99,8.00',
            'SKU-3,Brand New Product,29.99,12.00',
            'SKU-4,Shared Product,39.99,16.00',
        ]).PHP_EOL;

        $import = $this->fullyValidatedImport($content);
        $this->assertSame(4, TenantContext::run($this->marlin, fn () => $import->fresh()->valid_rows));

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit");
        $this->drainImportsQueue();

        $sharedProductCount = TenantContext::run($this->marlin, fn () => Product::query()->where('name', 'Shared Product')->count());
        $this->assertSame(1, $sharedProductCount);

        $variantProductIds = TenantContext::run(
            $this->marlin,
            fn () => Variant::query()->whereIn('sku', ['SKU-1', 'SKU-2', 'SKU-4'])->pluck('product_id')->unique()->values()->all(),
        );
        $this->assertCount(1, $variantProductIds, 'Cele 3 variante „Shared Product" trebuie să indice ACELAȘI produs.');

        $this->assertSame(2, TenantContext::run($this->marlin, fn () => Product::query()->count()));
    }

    private function fullyValidatedImport(string $csvContent): Import
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);
        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => ['SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ]);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run");
        $this->drainImportsQueue();

        return $import;
    }

    private function drainImportsQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'imports',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
