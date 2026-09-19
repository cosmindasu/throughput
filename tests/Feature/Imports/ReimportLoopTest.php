<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * US-IMP-01, bucla completă „corectare → reimport": raportul de erori descărcat, corectat,
 * reimportat — doar rândurile corectate se procesează, fără dublarea celor deja importate.
 * La scară mică (5 rânduri, 2 cu erori) — testul dedicat pe fixture-ul de 10.000 e separat
 * (`ImportFixtureTest`), per task brief.
 */
class ReimportLoopTest extends TestCase
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

    public function test_correcting_and_reimporting_the_error_report_only_processes_the_failed_rows(): void
    {
        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-1,Product A,9.99,4.00',
            'SKU-2,Product B,19.99,8.00',
            'SKU-3,Product C,29.99,12.00',
            'SKU-BAD-1,,9.99,4.00',
            'SKU-BAD-2,Product E,N/A,4.00',
        ]).PHP_EOL;

        $firstImport = $this->fullyCommittedImport($content);

        $firstFresh = TenantContext::run($this->marlin, fn () => $firstImport->fresh());
        $this->assertSame(3, $firstFresh->valid_rows);
        $this->assertSame(2, $firstFresh->error_rows);
        $this->assertSame(
            3,
            TenantContext::run($this->marlin, fn () => Variant::query()->count()),
            '9.800/3 rânduri valide importate la primul commit.',
        );

        // Descarcă raportul de erori — DOAR cele 2 rânduri eșuate, coloanele originale +
        // `error`.
        $errorReport = $this->actingAs($this->owner)->get("/marlin/imports/{$firstImport->getKey()}/errors");
        $errorReport->assertOk();

        $lines = array_values(array_filter(explode("\n", trim($errorReport->getContent()))));
        $this->assertCount(3, $lines, 'Antet + 2 rânduri eșuate.');
        // `str_getcsv`, nu comparație de șir brut: `fputcsv` pune ghilimele pe orice câmp cu
        // spațiu ("Product Name"), CSV perfect valid — nu o eroare de formatare.
        $this->assertSame(['SKU', 'Product Name', 'Price', 'Cost', 'error'], str_getcsv($lines[0]));

        // Corectează cele 2 rânduri (nume completat, preț numeric) — coloana `error` RĂMÂNE
        // în fișierul reimportat, exact scenariul real (utilizatorul editează CSV-ul
        // descărcat fără să șteargă coloana adăugată).
        $correctedRows = array_map(function (string $line) {
            $cells = str_getcsv($line);
            if ($cells[0] === 'SKU-BAD-1') {
                $cells[1] = 'Product D (fixed)';
            }
            if ($cells[0] === 'SKU-BAD-2') {
                $cells[2] = '9.99';
            }

            return implode(',', array_slice($cells, 0, 4));
        }, array_slice($lines, 1));

        $correctedContent = "SKU,Product Name,Price,Cost,error\n".implode("\n", $correctedRows)."\n";

        $secondImport = $this->fullyCommittedImport($correctedContent, expectAutoMapErrorColumnToNull: true);

        $secondFresh = TenantContext::run($this->marlin, fn () => $secondImport->fresh());
        $this->assertSame(2, $secondFresh->valid_rows, 'Doar cele 2 rânduri corectate.');
        $this->assertSame(0, $secondFresh->error_rows);
        $this->assertSame(Import::STATUS_COMPLETED, $secondFresh->status);

        // 3 (primul commit) + 2 (al doilea) = 5, FĂRĂ dublarea celor 3 deja importate.
        $allSkus = TenantContext::run($this->marlin, fn () => Variant::query()->pluck('sku')->sort()->values()->all());
        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-BAD-1', 'SKU-BAD-2'], $allSkus);
    }

    private function fullyCommittedImport(string $csvContent, bool $expectAutoMapErrorColumnToNull = false): Import
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);
        $import = TenantContext::run($this->marlin, fn () => Import::query()->latest()->firstOrFail());

        if ($expectAutoMapErrorColumnToNull) {
            $this->actingAs($this->owner)
                ->get("/marlin/imports/{$import->getKey()}")
                ->assertInertia(function (AssertableInertia $page): void {
                    $suggestions = collect($page->toArray()['props']['mappingSuggestions']);
                    $errorSuggestion = $suggestions->firstWhere('header', 'error');

                    $this->assertNotNull($errorSuggestion);
                    $this->assertNull($errorSuggestion['field'], 'Coloana "error" nu trebuie mapată automat pe niciun câmp.');
                });
        }

        $mapping = ['SKU' => 'sku', 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'];

        if ($expectAutoMapErrorColumnToNull) {
            // Coloana `error` a raportului reimportat rămâne NEMAPATĂ — exact ce ar trimite
            // UI-ul (`Imports/Show.tsx`) dacă utilizatorul lasă selectul pe „Don't import
            // this column", fără să șteargă coloana din fișier.
            $mapping['error'] = null;
        }

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", ['mapping' => $mapping]);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/dry-run");
        $this->drainImportsQueue();

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/commit");
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
