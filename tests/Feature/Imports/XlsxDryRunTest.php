<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * P2, review general — zero acoperire pe XLSX, deși e unul din cele două formate cerute de
 * §14.1. `ImportFileHeaders`/`ImportFileRowCounter`/`ImportRowsRangeReader` au cod dedicat
 * pentru `.xlsx` (filtre PhpSpreadsheet), netestat până acum decât prin citirea codului.
 */
class XlsxDryRunTest extends TestCase
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

    public function test_a_small_xlsx_file_validates_correctly(): void
    {
        $rows = [
            ['SKU', 'Product Name', 'Price', 'Cost'],
            ['SKU-1', 'Widget One', 9.99, 4.00],
            ['SKU-2', 'Widget Two', 19.99, 8.00],
            ['SKU-3', '', 9.99, 4.00], // nume lipsă — invalid.
        ];

        $file = $this->makeXlsxUpload($rows);

        $response = $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);
        $response->assertSessionDoesntHaveErrors();
        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());
        $this->assertSame('products.xlsx', $import->original_filename);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => ['SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ])->assertRedirect();

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_VALIDATED, $fresh->status);
        $this->assertSame(3, $fresh->total_rows);
        $this->assertSame(2, $fresh->valid_rows);
        $this->assertSame(1, $fresh->error_rows);

        $invalidRow = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('status', ImportRow::STATUS_INVALID)->sole(),
        );
        // Rândul 4 (antet = rândul 1, „SKU-3" e a treia linie de date).
        $this->assertSame(4, $invalidRow->row_number);
        $this->assertSame('product_name', $invalidRow->errors[0]['field']);
    }

    /**
     * Bug găsit la scrierea ACESTUI test, nu la citirea codului: `ImportRowsRangeReader`
     * calcula dimensiunea REALĂ a fișierului (`isLastChunk`) din `getHighestRow()` PE FOAIA
     * DEJA FILTRATĂ de `IReadFilter` — care reflectă doar rândurile PĂSTRATE (antetul +
     * chunk-ul curent), niciodată rândurile de după. Rezultat: primul chunk ieșea mereu
     * `isLastChunk = true`, indiferent cât de mare era fișierul — un XLSX mai mare decât UN
     * chunk se trunchia tăcut la primele `import_chunk_size` rânduri de date. `chunk_size = 2`
     * pe un fișier de 5 rânduri de date forțează 3 chunk-uri — dacă bug-ul ar mai exista,
     * `total_rows` ar rămâne 2, nu 5.
     */
    public function test_an_xlsx_file_larger_than_one_chunk_is_read_completely(): void
    {
        config(['throughput.limits.import_chunk_size' => 2]);

        $rows = [
            ['SKU', 'Product Name', 'Price', 'Cost'],
            ['SKU-1', 'Widget One', 9.99, 4.00],
            ['SKU-2', 'Widget Two', 19.99, 8.00],
            ['SKU-3', 'Widget Three', 29.99, 12.00],
            ['SKU-4', 'Widget Four', 39.99, 16.00],
            ['SKU-5', 'Widget Five', 49.99, 20.00],
        ];

        $file = $this->makeXlsxUpload($rows);

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file])
            ->assertSessionDoesntHaveErrors();
        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => ['SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ]);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run");
        $this->drainImportsQueue();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_VALIDATED, $fresh->status);
        $this->assertSame(5, $fresh->total_rows, 'Toate cele 5 rânduri de date, peste 3 chunk-uri — nu doar primul.');
        $this->assertSame(5, $fresh->valid_rows);
        $this->assertSame(0, $fresh->error_rows);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function makeXlsxUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
