<?php

namespace Tests\Feature\Imports;

use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportField;
use App\Support\Imports\ImportTemplateBuilder;
use Tests\TestCase;

/**
 * Golul reparat: etichetele ecranului de import erau engleză hardcodată
 * (`ImportField->label`, `label()` al celor 4 resurse) — un utilizator cu interfața în
 * franceză vedea „Company name"/„Accounts" indiferent de `locale`. FR-I18N-04 + BR-I18N-01
 * (specs.md §15.8, ADR-022).
 *
 * Valorile engleze de mai jos sunt scrise de mână (nu citite din `lang/en/imports.php`)
 * DELIBERAT: o aserțiune care ar compara catalogul cu el însuși ar trece verde chiar dacă
 * engleza vizibilă s-a schimbat la extragere — regula lotului cere identitate caracter cu
 * caracter cu literalele de dinainte de mutarea în catalog, verificată aici împotriva unei
 * referințe independente de fișierul modificat.
 */
class ImportLabelLocaleTest extends TestCase
{
    /** @var array<string, string> */
    private const RESOURCE_LABELS_EN = [
        'accounts' => 'Accounts',
        'contacts' => 'Contacts',
        'products' => 'Products',
        'variants' => 'Products/Variants',
    ];

    /** @var array<string, string> */
    private const RESOURCE_LABELS_FR = [
        'accounts' => 'Comptes',
        'contacts' => 'Contacts',
        'products' => 'Produits',
        'variants' => 'Produits/Variantes',
    ];

    /** @var array<string, array<string, string>> */
    private const FIELD_LABELS_EN = [
        'accounts' => [
            'name' => 'Company name',
            'domain' => 'Domain',
            'industry' => 'Industry',
            'phone' => 'Phone',
            'source' => 'Source',
        ],
        'contacts' => [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'email' => 'Email',
            'phone' => 'Phone',
            'title' => 'Job title',
            'account_name' => 'Company',
        ],
        'products' => [
            'name' => 'Product name',
            'category' => 'Category',
            'unit_of_measure' => 'Unit of measure',
        ],
        'variants' => [
            'sku' => 'SKU',
            'product_name' => 'Product name',
            'category' => 'Category',
            'unit_of_measure' => 'Unit of measure',
            'price' => 'Price',
            'cost' => 'Cost',
            'weight' => 'Weight',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const FIELD_LABELS_FR = [
        'accounts' => [
            // Apostrof tipografic (U+2019) — convenția franceză a proiectului, vezi nota de
            // la cheie în `lang/fr/imports.php`. Scris LITERAL aici, nu citit din catalog:
            // o referință care se ia din fișierul verificat n-ar prinde niciodată o schimbare.
            'name' => 'Nom de l’entreprise',
            'domain' => 'Domaine',
            'industry' => 'Secteur',
            'phone' => 'Téléphone',
            'source' => 'Source',
        ],
        'contacts' => [
            'first_name' => 'Prénom',
            'last_name' => 'Nom',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
            'title' => 'Poste',
            'account_name' => 'Société',
        ],
        'products' => [
            'name' => 'Nom du produit',
            'category' => 'Catégorie',
            'unit_of_measure' => 'Unité de mesure',
        ],
        'variants' => [
            'sku' => 'Référence',
            'product_name' => 'Nom du produit',
            'category' => 'Catégorie',
            'unit_of_measure' => 'Unité de mesure',
            'price' => 'Prix',
            'cost' => 'Coût',
            'weight' => 'Poids',
        ],
    ];

    /**
     * Etichetele de resursă și de câmp se traduc în franceză ȘI rămân identice cu cele
     * engleze de azi — ambele sensuri verificate explicit, ca o desperechere pe partea
     * engleză (o reformulare strecurată la extragere) să nu treacă neobservată doar fiindcă
     * franceza era corectă.
     */
    public function test_resource_and_field_labels_translate_to_french_and_english_stays_unchanged(): void
    {
        foreach (ImportableResources::map() as $type => $class) {
            $resource = new $class;

            $this->assertSame(self::RESOURCE_LABELS_EN[$type], $this->labelInLocale('en', fn () => $resource->label()));
            $this->assertSame(self::RESOURCE_LABELS_FR[$type], $this->labelInLocale('fr', fn () => $resource->label()));

            foreach ($resource->fields() as $field) {
                $this->assertSame(
                    self::FIELD_LABELS_EN[$type][$field->key],
                    $field->labelIn('en'),
                    "Eticheta EN a câmpului \"{$type}.{$field->key}\" nu mai e identică cu literalul de dinainte de extragere.",
                );
                $this->assertSame(
                    self::FIELD_LABELS_FR[$type][$field->key],
                    $field->labelIn('fr'),
                    "Eticheta FR a câmpului \"{$type}.{$field->key}\" nu corespunde traducerii așteptate.",
                );
            }
        }
    }

    /**
     * `ImportTemplateBuilder` — antetul CSV descărcabil urmează locale-ul CERERII curente:
     * francez sub `fr`, neschimbat (engleza de azi) sub `en`.
     */
    public function test_template_header_is_french_under_fr_and_unchanged_under_en(): void
    {
        foreach (ImportableResources::map() as $type => $class) {
            $resource = new $class;

            $headerEn = $this->headerRowInLocale('en', fn () => ImportTemplateBuilder::toCsvString($resource));
            $this->assertSame(array_values(self::FIELD_LABELS_EN[$type]), $headerEn);

            $headerFr = $this->headerRowInLocale('fr', fn () => ImportTemplateBuilder::toCsvString($resource));
            $this->assertSame(array_values(self::FIELD_LABELS_FR[$type]), $headerFr);
        }
    }

    /**
     * Garda BR-I18N-01, direcția FR → EN: un template descărcat CÂND interfața era în
     * franceză se remapează cu încredere „high" pe cheile stabile CÂND cel care reimportă
     * are interfața în engleză — antetele vin din `ImportTemplateBuilder` REAL, nu din
     * literale scrise de mână în test, ca proba să verifice ce produce codul.
     */
    public function test_a_french_template_remaps_with_high_confidence_under_english_app_locale(): void
    {
        foreach (ImportableResources::map() as $type => $class) {
            $resource = new $class;
            $fields = $resource->fields();

            $headers = $this->headerRowInLocale('fr', fn () => ImportTemplateBuilder::toCsvString($resource));
            $expectedFieldByHeader = array_combine($headers, array_map(fn ($field) => $field->key, $fields));

            $this->assertHighConfidenceRemap($headers, $fields, $expectedFieldByHeader, 'en', $type);
        }
    }

    /**
     * Garda BR-I18N-01, direcția SIMETRICĂ, EN → FR: un template descărcat sub engleză (deci
     * neschimbat față de azi) se remapează cu încredere „high" și când cel care reimportă are
     * interfața în franceză — potrivirea nu depinde de `App::getLocale()` curent, fiindcă
     * `ColumnMappingSuggester` compară pe `allLabels()` (ambele limbi), nu pe `label()`.
     */
    public function test_an_english_template_remaps_with_high_confidence_under_french_app_locale(): void
    {
        foreach (ImportableResources::map() as $type => $class) {
            $resource = new $class;
            $fields = $resource->fields();

            $headers = $this->headerRowInLocale('en', fn () => ImportTemplateBuilder::toCsvString($resource));
            $expectedFieldByHeader = array_combine($headers, array_map(fn ($field) => $field->key, $fields));

            $this->assertHighConfidenceRemap($headers, $fields, $expectedFieldByHeader, 'fr', $type);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<ImportField>  $fields
     * @param  array<string, string>  $expectedFieldByHeader
     */
    private function assertHighConfidenceRemap(array $headers, array $fields, array $expectedFieldByHeader, string $appLocale, string $resourceType): void
    {
        $previous = app()->getLocale();
        app()->setLocale($appLocale);

        try {
            $suggestions = ColumnMappingSuggester::suggest($headers, $fields);
        } finally {
            app()->setLocale($previous);
        }

        foreach ($suggestions as $suggestion) {
            $this->assertSame(
                $expectedFieldByHeader[$suggestion['header']],
                $suggestion['field'],
                "[{$resourceType}] Antetul \"{$suggestion['header']}\" ar trebui să mapeze pe \"{$expectedFieldByHeader[$suggestion['header']]}\" sub app locale \"{$appLocale}\".",
            );
            $this->assertSame(
                ColumnMappingSuggester::CONFIDENCE_HIGH,
                $suggestion['confidence'],
                "[{$resourceType}] Antetul \"{$suggestion['header']}\" ar trebui să aibă încredere \"high\" sub app locale \"{$appLocale}\".",
            );
        }
    }

    /** @return list<string> */
    private function headerRowInLocale(string $locale, callable $produceCsv): array
    {
        $csv = $this->labelInLocale($locale, $produceCsv);
        $firstLine = strtok($csv, "\r\n");

        return str_getcsv((string) $firstLine);
    }

    private function labelInLocale(string $locale, callable $produce): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $produce();
        } finally {
            app()->setLocale($previous);
        }
    }
}
