<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Plan §10, livrabilul de Fază 4: fixture-ul REAL de 10.000 de rânduri cu ~200 erori
 * plantate (§7.8, generat static în Faza 1) — proba uscată trebuie să raporteze EXACT
 * rândurile invalide, cu mesaj și număr de rând corecte pentru 100% dintre ele.
 *
 * Fixture-ul e scris pentru resursa `variants` (SKU + Price + Cost + Weight sunt câmpuri de
 * variantă, nu de produs „gol" — vezi docblock-ul `VariantImportResource`), deși numele
 * fișierului spune „products" — semnalat în raportul lotului.
 *
 * Grup Pest `slow`: singurul test din suită care procesează 10.000 de rânduri prin
 * lanțul real de joburi auto-continue (20 chunk-uri de 500 + 1 finalizare, la
 * `import_chunk_size` implicit). Rulează normal în suită (nu exclus implicit) — timpul
 * măsurat e raportat separat lotului, nu presupus.
 */
class ImportFixtureTest extends TestCase
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

    public function test_the_real_10000_row_fixture_reports_exactly_the_planted_errors(): void
    {
        $fixturePath = database_path('seeders/fixtures/import-products-with-errors.csv');
        $this->assertFileExists($fixturePath, 'Fixture-ul §7.8 trebuie să existe deja (generat în Faza 1).');

        $file = UploadedFile::fake()->createWithContent('import-products-with-errors.csv', file_get_contents($fixturePath));

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file])->assertRedirect();
        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => [
                'SKU' => 'sku',
                'Product Name' => 'product_name',
                'Category' => 'category',
                'Unit of Measure' => 'unit_of_measure',
                'Price' => 'price',
                'Cost' => 'cost',
                'Weight' => 'weight',
            ],
        ])->assertRedirect();

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();

        $start = microtime(true);
        $this->drainImportsQueue();
        $elapsed = round(microtime(true) - $start, 1);
        fwrite(STDERR, "\n[ImportFixtureTest] Dry-run pe 10.000 de rânduri: {$elapsed}s\n");

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_VALIDATED, $fresh->status);
        $this->assertSame(10000, $fresh->total_rows);
        $this->assertSame(9800, $fresh->valid_rows, '10.000 - 200 erori plantate (§7.8).');
        $this->assertSame(200, $fresh->error_rows);

        $invalidRows = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('status', ImportRow::STATUS_INVALID)->get(['row_number', 'errors']),
        );
        $this->assertCount(200, $invalidRows);

        // Numărul de rând corect pentru 100% dintre rândurile invalide (plan §10): cele trei
        // familii de erori plantate de `ImportFixtureSeeder`, pe intervale de rând FĂRĂ
        // suprapunere (verificat separat la scrierea seeder-ului) — SKU duplicat (rândurile
        // 201..2961, pas 40), preț non-numeric (3001..5761, pas 40), nume lipsă (6001..8361,
        // pas 40).
        $byRowNumber = $invalidRows->keyBy('row_number');

        $duplicateRowNumbers = range(201, 2961, 40);
        $nonNumericRowNumbers = range(3001, 5761, 40);
        $missingNameRowNumbers = range(6001, 8361, 40);

        $this->assertCount(70, $duplicateRowNumbers);
        $this->assertCount(70, $nonNumericRowNumbers);
        $this->assertCount(60, $missingNameRowNumbers);

        foreach ($duplicateRowNumbers as $rowNumber) {
            $this->assertTrue($byRowNumber->has($rowNumber), "Rândul {$rowNumber} (SKU duplicat) trebuia raportat invalid.");
            $this->assertSame('sku', $byRowNumber[$rowNumber]['errors'][0]['field']);
        }

        foreach ($nonNumericRowNumbers as $rowNumber) {
            $this->assertTrue($byRowNumber->has($rowNumber), "Rândul {$rowNumber} (preț non-numeric) trebuia raportat invalid.");
            $this->assertSame('price', $byRowNumber[$rowNumber]['errors'][0]['field']);
        }

        foreach ($missingNameRowNumbers as $rowNumber) {
            $this->assertTrue($byRowNumber->has($rowNumber), "Rândul {$rowNumber} (nume lipsă) trebuia raportat invalid.");
            $this->assertSame('product_name', $byRowNumber[$rowNumber]['errors'][0]['field']);
        }
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
