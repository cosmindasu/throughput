<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * P2 (lot i18n, „RunDryRunValidationJob fără locale") — `RunDryRunValidationAction::execute()`
 * capturează limba CERERII care pornește proba uscată (`App::getLocale()`, ADR-022,
 * FR-I18N-05) și o transmite explicit lanțului de joburi (`RunDryRunValidationJob`, chunk-ul
 * care se auto-continuă, `FinalizeImportDryRunJob`) — vezi docblock-urile acelor clase.
 * Fără fix, mesajele scrise în `import_rows.errors` (eticheta câmpului din validator,
 * `ImportField::label()`, ȘI mesajul de duplicat ÎN FIȘIER din `ImportDryRunFinalizer`) ar
 * moșteni limba ÎNTÂMPLĂTOARE a worker-ului la momentul rulării, nu limba utilizatorului
 * care a pornit importul — exact scurgerea documentată în
 * `tests/Feature/I18n/JobLocaleLeakTest.php`, aplicată aici.
 *
 * Worker-ul e forțat DELIBERAT pe limba OPUSĂ chiar înainte de a rula coada
 * (`App::setLocale()`, simulând un job anterior de altă limbă pe același worker de viață
 * lungă) — un test care ar trece „din întâmplare" (fiindcă `APP_LOCALE` implicit e `en`) n-ar
 * prinde o regresie a fix-ului.
 *
 * `import_chunk_size = 2` (ca `DryRunValidationTest`) forțează DOUĂ chunk-uri pentru cele 3
 * rânduri de date de mai jos — deci `RunDryRunValidationJob` se auto-continuă cel puțin o
 * dată ÎNAINTE de `FinalizeImportDryRunJob`, exercitând propagarea locale-ului la AMBELE
 * capete ale lanțului, nu doar la primul job dispecerizat.
 */
class DryRunValidationLocaleTest extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();

        config(['throughput.limits.import_chunk_size' => 2]);
    }

    public function test_a_french_users_dry_run_writes_french_messages_even_on_an_english_worker(): void
    {
        $owner = $this->makeMember($this->marlin, 'fr-owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $import = $this->uploadAndMap($owner, $this->fixtureContent());

        $this->actingAs($owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();

        // Simulează un worker de viață lungă care tocmai a terminat un job ENGLEZ.
        App::setLocale('en');
        $this->drainImportsQueue();

        $byRowNumber = $this->rowsByNumber($import);

        // Rând 3 (chunk 1) — câmp obligatoriu lipsă: eticheta interpolată de validator
        // (`ImportField::label()`) trebuie franceză, nu engleza implicită a worker-ului.
        $this->assertSame('product_name', $byRowNumber[3]->errors[0]['field']);
        $this->assertStringContainsStringIgnoringCase('Nom du produit', $byRowNumber[3]->errors[0]['message']);

        // Rând 4 (chunk 2, ULTIMUL) — duplicat ÎN FIȘIER cu rândul 2, detectat abia de
        // `ImportDryRunFinalizer` (`FinalizeImportDryRunJob`), DUPĂ ce ambele chunk-uri s-au
        // scris — trebuie franceză la fel, nu doar mesajele scrise de primul chunk.
        $this->assertSame('sku', $byRowNumber[4]->errors[0]['field']);
        $this->assertSame(
            'Valeur en double — déjà utilisée par une ligne précédente de ce fichier.',
            $byRowNumber[4]->errors[0]['message'],
        );
    }

    public function test_an_english_users_dry_run_writes_english_messages_even_on_a_french_worker(): void
    {
        // Locale implicit `en` — niciun `forceFill`, simetric cu testul de mai sus.
        $owner = $this->makeMember($this->marlin, 'en-owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $import = $this->uploadAndMap($owner, $this->fixtureContent());

        $this->actingAs($owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();

        // Simulează un worker de viață lungă care tocmai a terminat un job FRANCEZ.
        App::setLocale('fr');
        $this->drainImportsQueue();

        $byRowNumber = $this->rowsByNumber($import);

        $this->assertSame('product_name', $byRowNumber[3]->errors[0]['field']);
        $this->assertStringContainsStringIgnoringCase('Product name', $byRowNumber[3]->errors[0]['message']);

        $this->assertSame('sku', $byRowNumber[4]->errors[0]['field']);
        $this->assertSame(
            'Duplicate value — already used by an earlier row in this file.',
            $byRowNumber[4]->errors[0]['message'],
        );
    }

    /**
     * Rând 2: valid. Rând 3 (ultimul din chunk-ul 1, cu `import_chunk_size = 2`): nume de
     * produs lipsă — forțează mesajul validatorului cu eticheta câmpului interpolată. Rând 4
     * (singur în chunk-ul 2, ULTIMUL fișierului): SKU duplicat cu rândul 2, ÎN FIȘIER —
     * invizibil pentru chunk-ul lui (care nu vede rândul 2, scris de chunk-ul ANTERIOR),
     * detectat abia de `ImportDryRunFinalizer`.
     */
    private function fixtureContent(): string
    {
        return implode("\n", [
            'SKU,Product Name,Price,Cost',
            'SKU-001,Widget One,9.99,4.00',
            'SKU-002,,9.99,4.00',
            'SKU-001,Widget One Again,12.00,5.00',
        ]).PHP_EOL;
    }

    /** @return Collection<int, ImportRow> */
    private function rowsByNumber(Import $import)
    {
        $rows = TenantContext::run($this->marlin, fn () => ImportRow::query()->where('import_id', $import->getKey())->orderBy('row_number')->get());
        $this->clearDatabaseTenantContext();

        return $rows->keyBy('row_number');
    }

    private function uploadAndMap(User $owner, string $csvContent): Import
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $this->actingAs($owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);

        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
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
