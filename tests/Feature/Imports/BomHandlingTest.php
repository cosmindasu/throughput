<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\Resources\VariantImportResource;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * P3, review general — un antet cu BOM UTF-8 (`\xEF\xBB\xBF`, adăugat de Excel la „Save as
 * CSV UTF-8" pe Windows) funcționează CORECT azi, dar din întâmplare: `ColumnMappingSuggester::
 * normalize()` face `preg_replace('/[^a-z0-9]+/', ...)` FĂRĂ modificatorul `/u`, deci operează
 * pe BYTE-uri — cei 3 octeți ai BOM-ului sunt „nu e a-z0-9", deci dispar la normalizare exact
 * ca orice altă punctuație, iar antetul rămas se potrivește corect pe alias. Regresie, nu
 * proiectare deliberată — testul îngheață comportamentul actual, ca o schimbare viitoare a lui
 * `normalize()` (ex: adăugarea `/u` pentru suport Unicode complet) să nu-l strice tăcut.
 */
class BomHandlingTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_auto_mapping_still_matches_a_bom_prefixed_header(): void
    {
        $suggestions = ColumnMappingSuggester::suggest([self::BOM.'SKU', 'Product Name'], (new VariantImportResource)->fields());

        $this->assertSame('sku', $suggestions[0]['field']);
        $this->assertSame('high', $suggestions[0]['confidence']);
    }

    public function test_a_file_with_a_bom_prefixed_header_imports_end_to_end(): void
    {
        $content = self::BOM.implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-BOM-1,Widget One,9.99,4.00',
        ]).PHP_EOL;

        $file = UploadedFile::fake()->createWithContent('products.csv', $content);

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file])
            ->assertSessionDoesntHaveErrors();
        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());

        // Maparea trimisă de UI folosește exact antetul BOM-prefixat citit de server
        // (`ImportFileHeaders::read()`) — la fel ca `Imports/Show.tsx`, care nu inventează
        // un antet „curățat".
        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => [self::BOM.'SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ])->assertSessionDoesntHaveErrors();

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run");
        $this->drainImportsQueue();

        $afterDryRun = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(1, $afterDryRun->valid_rows);
        $this->assertSame(0, $afterDryRun->error_rows);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit");
        $this->drainImportsQueue();

        $this->assertTrue(TenantContext::run($this->marlin, fn () => Variant::query()->where('sku', 'SKU-BOM-1')->exists()));
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
