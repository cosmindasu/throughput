<?php

namespace Tests\Feature\Imports;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Imports\ImportableResources;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * TEST-05 (audit 2026-09-23, §11-teste.md) — `imports.template` n-avea NICIUN test, deși
 * are logică reală (`ImportTemplateBuilder`): antetul EXACT pe care `ColumnMappingSuggester`
 * îl va remapa la reimport, tradus în locale-ul cererii (FR-I18N-04). `ImportAccessTest`
 * acoperă restul rutelor de import (index/create/store/show/dry-run/commit), niciodată
 * aceasta. `ImportLabelLocaleTest` verifică deja `ImportTemplateBuilder::toCsvString()`
 * apelat DIRECT — aici prin ruta HTTP reală (autorizare + content-type + filename inclus).
 */
class ImportTemplateDownloadTest extends TestCase
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

    /**
     * Antetul EXACT (ordine + etichete), content-type și numele fișierului, pentru fiecare
     * resursă importabilă — sub engleza implicită (§8.1, `lang/en/imports.php`).
     */
    public function test_the_template_has_the_expected_header_content_type_and_filename_per_resource(): void
    {
        $expectedHeaders = [
            'accounts' => ['Company name', 'Domain', 'Industry', 'Phone', 'Source'],
            'contacts' => ['First name', 'Last name', 'Email', 'Phone', 'Job title', 'Company'],
            'products' => ['Product name', 'Category', 'Unit of measure'],
            'variants' => ['SKU', 'Product name', 'Category', 'Unit of measure', 'Price', 'Cost', 'Weight'],
        ];

        foreach ($expectedHeaders as $type => $header) {
            $response = $this->actingAs($this->owner)->get("/marlin/imports/template/{$type}");

            $response->assertOk();
            $response->assertHeader('Content-Disposition', 'attachment; filename="'.$type.'-import-template.csv"');
            $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

            $content = (string) $response->getContent();
            $this->assertStringStartsNotWith("\xEF\xBB\xBF", $content, 'ImportTemplateBuilder nu scrie BOM UTF-8.');

            $firstLine = strtok($content, "\r\n");
            $this->assertSame($header, str_getcsv((string) $firstLine), "Antetul template-ului \"{$type}\" nu se potrivește cu ImportField-urile resursei.");
        }
    }

    public function test_all_importable_resource_types_have_a_downloadable_template(): void
    {
        foreach (ImportableResources::types() as $type) {
            $this->actingAs($this->owner)->get("/marlin/imports/template/{$type}")->assertOk();
        }
    }

    /** `{resourceType}` e restricționat la nivel de rută (`whereIn`) — orice altă valoare e 404. */
    public function test_an_unknown_resource_type_is_not_found(): void
    {
        $this->actingAs($this->owner)->get('/marlin/imports/template/bogus')->assertNotFound();
    }

    /**
     * FR-I18N-04 — antetul urmează locale-ul cererii, nu o engleză fixă. `ImportLabelLocaleTest`
     * verifică asta apelând `ImportTemplateBuilder` direct; aici prin ruta HTTP reală, cu
     * limba selectată cum o vede un utilizator autentificat — `users.locale`
     * (`LocalePreference::resolveForRequest()` pasul 1, câștigă înaintea cookie-ului).
     */
    public function test_the_template_header_is_french_when_the_user_prefers_french(): void
    {
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $response = $this->actingAs($this->owner)
            ->get('/marlin/imports/template/products');

        $response->assertOk();

        $firstLine = strtok((string) $response->getContent(), "\r\n");
        $this->assertSame(['Nom du produit', 'Catégorie', 'Unité de mesure'], str_getcsv((string) $firstLine));
    }

    public function test_an_agent_cannot_download_a_template(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get('/marlin/imports/template/products')->assertForbidden();
    }

    public function test_a_viewer_cannot_download_a_template(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->get('/marlin/imports/template/products')->assertForbidden();
    }
}
