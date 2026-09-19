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
 * Pasul 3 — Probă uscată (§14.1 pct. 3, US-IMP-01). Fișier MIC, cu erori CUNOSCUTE la
 * rânduri CUNOSCUTE — verifică exact numărul de rând și mesajul, plus FR-IMP-01 (duplicate
 * ÎN FIȘIER și FAȚĂ DE BAZĂ). `import_chunk_size` mic (2) forțează MAI MULTE chunk-uri
 * (deci mai multe invocări separate ale `RunDryRunValidationJob`, auto-continuare) — nu un
 * singur job care ar vedea tot fișierul deodată.
 */
class DryRunValidationTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        config(['throughput.limits.import_chunk_size' => 2]);
    }

    public function test_dry_run_reports_exact_row_numbers_and_messages_for_every_planted_error(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $product = Product::create(['name' => 'Existing Product', 'unit_of_measure' => 'each', 'is_active' => true]);
            Variant::create(['product_id' => $product->getKey(), 'sku' => 'DUPLICATE-IN-DB', 'price' => 1, 'cost' => 1, 'is_active' => true]);
        });
        $this->clearDatabaseTenantContext();

        // Rând 2: valid. Rând 3: SKU duplicat cu rândul 2 (ÎN FIȘIER). Rând 4: preț
        // non-numeric. Rând 5: nume de produs lipsă. Rând 6: SKU deja existent ÎN BAZĂ.
        // Rând 7: valid.
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-001,Widget One,9.99,4.00',
            'SKU-001,Widget One Again,12.00,5.00',
            'SKU-003,Widget Three,N/A,4.00',
            'SKU-004,,9.99,4.00',
            'DUPLICATE-IN-DB,Widget Five,9.99,4.00',
            'SKU-006,Widget Six,9.99,4.00',
        ]).PHP_EOL;

        $import = $this->uploadAndMap($content);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_VALIDATED, $fresh->status);
        $this->assertSame(6, $fresh->total_rows);
        $this->assertSame(2, $fresh->valid_rows);
        $this->assertSame(4, $fresh->error_rows);

        $rows = TenantContext::run($this->marlin, fn () => ImportRow::query()->where('import_id', $import->getKey())->orderBy('row_number')->get());
        $this->assertCount(6, $rows);

        $byRowNumber = $rows->keyBy('row_number');

        $this->assertSame(ImportRow::STATUS_VALID, $byRowNumber[2]->status);
        $this->assertSame('SKU-001', $byRowNumber[2]->raw_data['SKU']);

        $this->assertSame(ImportRow::STATUS_INVALID, $byRowNumber[3]->status);
        $this->assertSame('sku', $byRowNumber[3]->errors[0]['field']);
        $this->assertStringContainsString('Duplicate', $byRowNumber[3]->errors[0]['message']);

        $this->assertSame(ImportRow::STATUS_INVALID, $byRowNumber[4]->status);
        $this->assertSame('price', $byRowNumber[4]->errors[0]['field']);

        $this->assertSame(ImportRow::STATUS_INVALID, $byRowNumber[5]->status);
        $this->assertSame('product_name', $byRowNumber[5]->errors[0]['field']);

        $this->assertSame(ImportRow::STATUS_INVALID, $byRowNumber[6]->status);
        $this->assertSame('sku', $byRowNumber[6]->errors[0]['field']);
        $this->assertStringContainsString('Already exists', $byRowNumber[6]->errors[0]['message']);

        $this->assertSame(ImportRow::STATUS_VALID, $byRowNumber[7]->status);

        // BR-IMP-01 — `raw_data` păstrat inclusiv pentru rândurile eșuate.
        $this->assertSame('N/A', $byRowNumber[4]->raw_data['Price']);
    }

    /**
     * Regresie, găsită de suita E2E (Faza 4): acțiunea golea contoarele, dar NU muta
     * `status` din `mapped`. Două consecințe, ambele invizibile pentru testele care
     * verificau doar rezultatul final al probei uscate:
     *
     *  1. Cererea se întorcea cu importul tot pe `mapped`, deci ecranul nu se considera „în
     *     lucru" și nu pornea polling-ul — pagina rămânea blocată pe „Step 2 of 4" la
     *     nesfârșit, deși pe server proba uscată se termina corect în câteva secunde.
     *  2. Garda `where('status', MAPPED)` din acțiune nu serializa nimic cât timp `status`
     *     rămânea `mapped`: un al doilea POST trecea la fel de bine ca primul.
     *
     * Testul verifică starea IMEDIAT după cerere, ÎNAINTE de a rula coada — exact fereastra
     * pe care o rata suita până acum.
     */
    public function test_requesting_a_dry_run_moves_the_import_to_validating_before_any_job_runs(): void
    {
        $import = $this->uploadAndMap("SKU,Product Name,Price,Cost\nSKU-1,Widget,10.00,5.00\n");

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run");

        $fresh = TenantContext::run($this->marlin, fn () => Import::query()->findOrFail($import->getKey()));
        $this->assertSame(Import::STATUS_VALIDATING, $fresh->status);

        // Al doilea POST, pe același import, e acum refuzat — garda chiar serializează.
        $this->actingAs($this->owner)
            ->post("/marlin/imports/{$import->getKey()}/dry-run")
            ->assertSessionHasErrors('status');
    }

    private function uploadAndMap(string $csvContent): Import
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);

        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => ['SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ]);

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
