<?php

namespace Tests\Feature\Imports;

use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\ImportField;
use App\Support\Imports\ImportRowMapper;
use App\Support\Imports\Resources\AccountImportResource;
use App\Support\Imports\Resources\ContactImportResource;
use App\Support\Imports\Resources\ProductImportResource;
use App\Support\Imports\Resources\VariantImportResource;
use Tests\TestCase;

/**
 * BR-I18N-01 (specs.md §15.8, ADR-022) — round-trip: un CSV cu antete FRANȚUZEȘTI (fie
 * exportat dintr-un mediu cu interfața în franceză, odată ce `app/Support/Lists/*.php` va
 * traduce headerele — afara perimetrului acestui lot, vezi raportul —, fie scris de mână de
 * un utilizator francofon) se REIMPORTĂ fără intervenție manuală de mapare: aliasurile FR
 * adăugate pe `ImportField` (`AccountImportResource`/`ContactImportResource`/
 * `ProductImportResource`/`VariantImportResource`) fac `ColumnMappingSuggester` să recunoască
 * antetele franceze cu încredere „high", exact ca la engleză.
 *
 * Maparea rămâne pe CHEIE STABILĂ internă (`name`, `email`, `sku`, ...) indiferent de
 * `locale` — `ImportRowMapper::apply()` (NEATINS de acest lot, per BR-I18N-01) primește
 * `{antet_francez: cheie_stabilă}` și produce un rând keiat pe cheia stabilă, la fel ca la
 * un CSV englezesc. Asta e proba de „round-trip": header francez intră, cheie stabilă iese.
 */
class FrenchHeaderAliasRoundTripTest extends TestCase
{
    public function test_a_french_account_export_header_row_maps_automatically(): void
    {
        $headers = ["Nom de l'entreprise", 'Domaine', 'Secteur', 'Téléphone', 'Source'];
        $expectedFieldByHeader = [
            "Nom de l'entreprise" => 'name',
            'Domaine' => 'domain',
            'Secteur' => 'industry',
            'Téléphone' => 'phone',
            'Source' => 'source',
        ];

        $this->assertHighConfidenceRoundTrip($headers, (new AccountImportResource)->fields(), $expectedFieldByHeader);
    }

    public function test_a_french_contact_export_header_row_maps_automatically(): void
    {
        $headers = ['Prénom', 'Nom de famille', 'Adresse e-mail', 'Téléphone', 'Poste', 'Société'];
        $expectedFieldByHeader = [
            'Prénom' => 'first_name',
            'Nom de famille' => 'last_name',
            'Adresse e-mail' => 'email',
            'Téléphone' => 'phone',
            'Poste' => 'title',
            'Société' => 'account_name',
        ];

        $this->assertHighConfidenceRoundTrip($headers, (new ContactImportResource)->fields(), $expectedFieldByHeader);
    }

    public function test_a_french_product_export_header_row_maps_automatically(): void
    {
        $headers = ['Nom du produit', 'Catégorie', 'Unité de mesure'];
        $expectedFieldByHeader = [
            'Nom du produit' => 'name',
            'Catégorie' => 'category',
            'Unité de mesure' => 'unit_of_measure',
        ];

        $this->assertHighConfidenceRoundTrip($headers, (new ProductImportResource)->fields(), $expectedFieldByHeader);
    }

    public function test_a_french_variant_export_header_row_maps_automatically(): void
    {
        $headers = ['Référence', 'Nom du produit', 'Catégorie', 'Unité de mesure', 'Prix', 'Coût', 'Poids'];
        $expectedFieldByHeader = [
            'Référence' => 'sku',
            'Nom du produit' => 'product_name',
            'Catégorie' => 'category',
            'Unité de mesure' => 'unit_of_measure',
            'Prix' => 'price',
            'Coût' => 'cost',
            'Poids' => 'weight',
        ];

        $this->assertHighConfidenceRoundTrip($headers, (new VariantImportResource)->fields(), $expectedFieldByHeader);
    }

    /**
     * Un antet FR care nu are alias nu e ghicit forțat — exact comportamentul „no forced
     * guess" deja verificat pentru engleză în `ColumnMappingSuggesterTest`. Aliasurile
     * ADAUGĂ recunoaștere, nu schimbă pragurile de încredere ale euristicii.
     */
    public function test_an_unrelated_french_header_still_gets_no_suggestion(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(
            ['Référence interne inconnue Zzyzx'],
            (new ContactImportResource)->fields(),
        );

        $this->assertNull($suggestions[0]['field']);
        $this->assertSame(ColumnMappingSuggester::CONFIDENCE_NONE, $suggestions[0]['confidence']);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<ImportField>  $fields
     * @param  array<string, string>  $expectedFieldByHeader
     */
    private function assertHighConfidenceRoundTrip(array $headers, array $fields, array $expectedFieldByHeader): void
    {
        $suggestions = ColumnMappingSuggester::suggest($headers, $fields);
        $suggestedFieldByHeader = [];

        foreach ($suggestions as $suggestion) {
            $this->assertSame(
                $expectedFieldByHeader[$suggestion['header']],
                $suggestion['field'],
                "Antetul FR \"{$suggestion['header']}\" ar trebui să mapeze pe \"{$expectedFieldByHeader[$suggestion['header']]}\".",
            );
            $this->assertSame(
                ColumnMappingSuggester::CONFIDENCE_HIGH,
                $suggestion['confidence'],
                "Antetul FR \"{$suggestion['header']}\" ar trebui să aibă încredere \"high\" (alias exact), nu doar similaritate.",
            );

            $suggestedFieldByHeader[$suggestion['header']] = $suggestion['field'];
        }

        // Partea a doua a round-trip-ului: maparea PROPRIU-ZISĂ (`ImportRowMapper`,
        // NEATINS de acest lot) trebuie să producă un rând keiat pe cheia STABILĂ, nu pe
        // antetul francez — exact ce ar consuma `writeRow()` mai departe, indiferent de
        // limba în care a fost scris fișierul.
        $rawRow = array_combine($headers, array_map(fn (string $header) => "valeur pour {$header}", $headers));
        $mapped = ImportRowMapper::apply($rawRow, $suggestedFieldByHeader);

        foreach ($expectedFieldByHeader as $header => $stableKey) {
            $this->assertArrayHasKey($stableKey, $mapped);
            $this->assertSame("valeur pour {$header}", $mapped[$stableKey]);
        }
    }
}
