<?php

namespace Tests\Feature\Imports;

use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\Resources\AccountImportResource;
use App\Support\Imports\Resources\ContactImportResource;
use App\Support\Imports\Resources\VariantImportResource;
use Tests\TestCase;

/**
 * US-IMP-02 — auto-mapare euristică cu indicator de încredere per coloană. Pur PHP
 * (`ColumnMappingSuggester` nu atinge baza), dar rulează ca Feature ca restul suitei de
 * import — nicio nevoie reală de o suită `Unit` separată doar pentru asta.
 */
class ColumnMappingSuggesterTest extends TestCase
{
    public function test_an_exact_alias_match_gets_high_confidence(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(['Company Name', 'Phone'], (new AccountImportResource)->fields());

        $byHeader = collect($suggestions)->keyBy('header');

        $this->assertSame('name', $byHeader['Company Name']['field']);
        $this->assertSame('high', $byHeader['Company Name']['confidence']);
        $this->assertSame('phone', $byHeader['Phone']['field']);
        $this->assertSame('high', $byHeader['Phone']['confidence']);
    }

    /**
     * Fiecare variantă de scriere e testată SEPARAT (un singur antet per apel) — într-un
     * fișier real, o singură coloană ar purta unul dintre aceste nume, niciodată toate trei
     * simultan; testate împreună, „claim la prima potrivire" ar împinge a doua/a treia
     * variantă spre alt câmp prin similaritate, ceea ce nu spune nimic despre normalizare.
     */
    public function test_header_normalization_ignores_case_spacing_and_punctuation(): void
    {
        foreach (['unit_of_measure', 'UNIT-OF-MEASURE', 'Unit Of Measure'] as $header) {
            $suggestions = ColumnMappingSuggester::suggest([$header], (new VariantImportResource)->fields());

            $this->assertSame('unit_of_measure', $suggestions[0]['field'], "Header \"{$header}\" should map to unit_of_measure.");
            $this->assertSame('high', $suggestions[0]['confidence']);
        }
    }

    /**
     * US-IMP-02, task brief — „o coloană care trebuie să iasă cu încredere scăzută":
     * un antet fără nicio relație plauzibilă cu vreun câmp al resursei nu primește o
     * sugestie (rămâne nemapat, pentru remapare manuală), nu o ghicire forțată.
     */
    public function test_an_unrelated_header_gets_no_suggestion(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(['Zzyzx Internal Reference Code'], (new ContactImportResource)->fields());

        $this->assertNull($suggestions[0]['field']);
        $this->assertSame('none', $suggestions[0]['confidence']);
    }

    /**
     * Un header parțial suprapus (nu exact, nu complet străin) — încredere „medium"/„low",
     * nu „high". `first_name`/`last_name` sunt candidați apropiați de „First" pe distanța
     * de similaritate, deci pragul separă corect cele trei trepte.
     */
    public function test_a_partial_match_gets_a_lower_confidence_than_an_exact_one(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(['First'], (new ContactImportResource)->fields());

        $this->assertSame('first_name', $suggestions[0]['field']);
        $this->assertNotSame('high', $suggestions[0]['confidence']);
    }

    /**
     * US-IMP-01, bucla de reimport — coloana `error` a raportului de erori
     * (`ImportErrorReportBuilder`) NU se mapează niciodată automat, indiferent de resursă.
     * Găsit la testare: „error" ajunge la 46% similaritate cu „category" (litere comune
     * întâmplătoare), peste orice prag „low" rezonabil — excluderea e explicită, nu doar
     * un prag mai strict (vezi docblock-ul clasei).
     */
    public function test_the_reserved_error_column_never_gets_auto_mapped(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(['error'], (new VariantImportResource)->fields());

        $this->assertNull($suggestions[0]['field']);
        $this->assertSame('none', $suggestions[0]['confidence']);
    }

    public function test_each_field_is_claimed_at_most_once(): void
    {
        $suggestions = ColumnMappingSuggester::suggest(['Phone', 'Phone Number'], (new AccountImportResource)->fields());

        $claimedFields = array_filter(array_column($suggestions, 'field'));

        $this->assertCount(count($claimedFields), array_unique($claimedFields));
    }
}
