<?php

namespace Tests\Feature\I18n;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Lotul I18N Server Strings 2 (I18N-07, I18N-08, HTTP-04) — vezi:
 *   - `app/Support/Imports/ImportDryRunChunkProcessor.php` (I18N-07, mesajul de duplicat);
 *   - `app/Support/Exports/ExportFormat.php` (I18N-08, formatul necunoscut);
 *   - `resources/views/errors/*.blade.php` (HTTP-04, vederile de eroare proprii).
 *
 * NU acoperă I18N-10 (ștergerea `roles.agent`/`roles.viewer`): o cheie ȘTEARSĂ nu are ce
 * comportament să verifice printr-un test propriu — garda ei e `php artisan i18n:coverage`
 * (simetria en↔fr rămâne, cu DOUĂ chei în loc de patru) plus grep-ul deja făcut înainte de
 * ștergere (niciun `__('roles.agent')`/`__('roles.viewer')` în `app/`, `resources/views`,
 * `routes`, `tests`).
 */
class ServerStringsLot2Test extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();
    }

    /**
     * I18N-07 — `ImportDryRunChunkProcessor::process()` rulează în
     * `App\Jobs\Imports\RunDryRunValidationJob`, care NU apelează `App::setLocale()` (vezi
     * comentariul de-acolo). Testul folosește deci EXACT calea prin care limba ajunge corectă
     * astăzi — coincidental, nu garantat: cererea `POST .../dry-run` trece prin grupul `web`
     * (`App\Http\Middleware\SetLocale`, rezolvat din `users.locale` al proprietarului FR) și
     * FIXEAZĂ `App::currentLocale()` la 'fr' pentru tot restul procesului PHP; `queue:work`
     * rulează imediat după, ÎN ACELAȘI proces, deci jobul moștenește franceza — nu pentru că
     * ar seta-o el însuși. Vezi `tests/Feature/I18n/JobLocaleLeakTest.php` pentru exact acest
     * mecanism aplicat altor joburi, ȘI pentru cazul în care moștenirea eșuează (două joburi
     * succesive, de limbi diferite, pe același worker).
     */
    public function test_a_duplicate_value_at_import_reports_a_french_message_for_a_french_owner(): void
    {
        $owner = $this->makeMember($this->marlin, 'proprietaire@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        TenantContext::run($this->marlin, function (): void {
            $product = Product::create(['name' => 'Produit Existant', 'unit_of_measure' => 'each', 'is_active' => true]);
            Variant::create(['product_id' => $product->getKey(), 'sku' => 'DEJA-EXISTANT', 'price' => 1, 'cost' => 1, 'is_active' => true]);
        });
        $this->clearDatabaseTenantContext();

        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'DEJA-EXISTANT,Nouveau Produit,9.99,4.00',
        ]).PHP_EOL;

        $import = $this->uploadAndMap($owner, $content);

        $this->actingAs($owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();
        $this->drainImportsQueue();

        $row = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('row_number', 2)->sole(),
        );
        $this->clearDatabaseTenantContext();

        $this->assertSame(ImportRow::STATUS_INVALID, $row->status);
        $this->assertSame('sku', $row->errors[0]['field']);
        $this->assertStringContainsString(
            'Existe déjà',
            $row->errors[0]['message'],
            "Mesajul de duplicat a rămas în engleză pentru un proprietar francez — vezi __('imports.validation.duplicate_value').",
        );
        $this->assertStringNotContainsString('Already exists', $row->errors[0]['message']);
    }

    /**
     * Simetria obligatorie față de testul de mai sus — fără ea, testul FR ar trece verde
     * și dacă cineva ar fixa franceza necondiționat în `ImportDryRunChunkProcessor`.
     */
    public function test_a_duplicate_value_at_import_stays_in_english_for_an_english_owner(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        TenantContext::run($this->marlin, function (): void {
            $product = Product::create(['name' => 'Existing Product', 'unit_of_measure' => 'each', 'is_active' => true]);
            Variant::create(['product_id' => $product->getKey(), 'sku' => 'ALREADY-EXISTS', 'price' => 1, 'cost' => 1, 'is_active' => true]);
        });
        $this->clearDatabaseTenantContext();

        $content = implode("\n", [
            'SKU,Product Name,Price,Cost',
            'ALREADY-EXISTS,New Product,9.99,4.00',
        ]).PHP_EOL;

        $import = $this->uploadAndMap($owner, $content);

        $this->actingAs($owner)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertRedirect();
        $this->drainImportsQueue();

        $row = TenantContext::run(
            $this->marlin,
            fn () => ImportRow::query()->where('import_id', $import->getKey())->where('row_number', 2)->sole(),
        );
        $this->clearDatabaseTenantContext();

        $this->assertStringContainsString('Already exists', $row->errors[0]['message']);
    }

    /**
     * I18N-08 — cererea forțează `Accept: application/json` (`getJson()`), deliberat: pe o
     * navigare simplă de browser (`<a href>`, fără JSON/Inertia — exact cazul descris în
     * docblock-ul `ExportFormat::fromRequest()`), Laravel randează excepția prin
     * `Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer`, care în afara
     * `app.debug` arată DOAR textul generic de status HTTP ("Unprocessable Entity"),
     * niciodată `$exception->getMessage()` (verificat direct în vendor/symfony — proiectul
     * n-are `resources/views/errors/422.blade.php`, nici Laravel unul implicit, pentru 422).
     * Pe ramura JSON însă, `Handler::convertExceptionToArray()` ÎNTOARCE mesajul exact al
     * oricărei `HttpException`, indiferent de `app.debug` — calea prin care mesajul chiar
     * ajunge vizibil (client JS care citește `error.response.data.message`, ca la orice alt
     * `abort($cod, $mesaj)` din aplicație).
     */
    public function test_export_with_an_unknown_format_returns_a_french_message_for_a_french_owner(): void
    {
        $owner = $this->makeMember($this->marlin, 'proprietaire@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/orders/export?format=xlsx');

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Format d’export inconnu "xlsx". Utilisez csv, pdf ou zip.',
        ]);
    }

    public function test_export_with_an_unknown_format_stays_in_english_for_an_english_owner(): void
    {
        $owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($owner)->getJson('/marlin/orders/export?format=xlsx');

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Unknown export format "xlsx". Use csv, pdf or zip.',
        ]);
    }

    /**
     * HTTP-04 — cazul care NU poate trece prin `SetLocale` (grupul `web` nu se aplică
     * niciodată unei rute inexistente, vezi `bootstrap/app.php`). VIZITATOR, nu utilizator
     * autentificat, ca în `tests/Feature/I18n/ErrorViewLocaleTest.php`: pe calea asta
     * sesiunea nu a pornit, deci limba vine strict din cookie-ul `locale` (necriptat,
     * exceptat explicit în `bootstrap/app.php`).
     *
     * Verifică `<html lang>` ȘI descrierea nou adăugată (`lang/fr.json`), nu doar `<title>`
     * (deja acoperit de `ErrorViewLocaleTest`) — proba directă că vederea PROPRIE
     * (`resources/views/errors/404.blade.php`), nu cea din `vendor/`, e cea randată.
     */
    public function test_a_missing_route_renders_the_custom_404_view_in_french_for_a_french_visitor(): void
    {
        $response = $this->withUnencryptedCookie('locale', 'fr')->get('/marlin/aceasta-ruta-nu-exista');

        $response->assertNotFound();
        $html = $response->getContent();

        $this->assertStringContainsString('<html lang="fr">', $html);
        $this->assertStringContainsString('Page introuvable', $html);
        $this->assertStringContainsString(
            'La page que vous recherchez est introuvable ou a peut-être été déplacée.',
            $html,
        );
        $this->assertSame(1, substr_count($html, '<h1>'), 'Vederea 404 trebuie să aibă exact un <h1>.');
        $this->assertStringContainsString('<main>', $html);
    }

    /**
     * Celălalt capăt (403): sesiunea A pornit, deci limba vine din `users.locale` — la fel
     * ca `ErrorViewLocaleTest::test_a_denied_screen_renders_its_403_page_in_french...`, dar
     * verificând aici conținutul propriu al vederii (`<html lang>`, descrierea), nu doar
     * `<title>`.
     */
    public function test_a_denied_screen_renders_the_custom_403_view_in_french_for_a_french_user(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $viewer->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($viewer)->get('/marlin/activity');

        $response->assertForbidden();
        $html = $response->getContent();

        $this->assertStringContainsString('<html lang="fr">', $html);
        $this->assertStringContainsString('Accès refusé', $html);
        $this->assertSame(1, substr_count($html, '<h1>'), 'Vederea 403 trebuie să aibă exact un <h1>.');
    }

    /**
     * "un test că fiecare vedere din resources/views/errors se randează
     * (`view('errors.500')->render()`) în ambele limbi fără excepții" — literal, plus
     * `errors.403`, care CITEȘTE `$exception` când e prezentă: i se dă o `HttpException`
     * goală (fără mesaj), ca pe calea reală când `abort(403)` nu poartă un al doilea
     * argument, ȘI se verifică separat mai jos că vederea rămâne randabilă și FĂRĂ nimic
     * injectat (`isset($exception)` din vedere).
     */
    public function test_every_error_view_renders_in_both_locales_without_exceptions(): void
    {
        $originalLocale = App::currentLocale();

        try {
            foreach (['403', '404', '419', '429', '500', '503'] as $code) {
                foreach (['en', 'fr'] as $locale) {
                    App::setLocale($locale);

                    $html = view('errors.'.$code, ['exception' => new HttpException((int) $code)])->render();

                    $this->assertStringContainsString('<html lang="'.$locale.'">', $html, "errors.{$code} ({$locale})");
                    $this->assertStringContainsString('<main>', $html, "errors.{$code} ({$locale})");
                    $this->assertSame(1, substr_count($html, '<h1>'), "errors.{$code} ({$locale}) trebuie să aibă exact un <h1>.");
                }
            }

            // `errors.403` fără nicio variabilă injectată — vezi docblock-ul din
            // `resources/views/errors/403.blade.php` (`isset($exception)`). Verificare pe
            // un fragment FĂRĂ apostrof: `@section(nume, valoare)` (formă pe două argumente)
            // trece conținutul prin `e()` (`ManagesLayouts::startSection()`), deci textul
            // englez apare ca `don&#039;t` în HTML — corect, nu o regresie de reparat aici.
            App::setLocale('en');
            $html = view('errors.403')->render();
            $this->assertStringContainsString('permission to access this page.', $html);
        } finally {
            App::setLocale($originalLocale);
        }
    }

    private function uploadAndMap(User $owner, string $csvContent): Import
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', $csvContent);

        $this->actingAs($owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);

        $import = TenantContext::run($this->marlin, fn () => Import::query()->latest('created_at')->firstOrFail());

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
