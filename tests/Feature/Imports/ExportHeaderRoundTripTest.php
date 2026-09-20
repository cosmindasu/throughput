<?php

namespace Tests\Feature\Imports;

use App\Support\Imports\ColumnMappingSuggester;
use App\Support\Imports\Resources\AccountImportResource;
use App\Support\Imports\Resources\ContactImportResource;
use App\Support\Lists\AccountList;
use App\Support\Lists\ContactList;
use Tests\TestCase;

/**
 * BR-I18N-01 — round-trip REAL, spre deosebire de `FrenchHeaderAliasRoundTripTest`, care
 * pornește de la antete franceze scrise de mână.
 *
 * Aici anteturile vin din `exportHeaders()` al listei reale, randate cu `locale = fr` —
 * adică exact octeții pe care i-ar produce un export făcut de un utilizator francofon.
 * Dacă cineva schimbă o traducere din `lang/fr/exports.php` cu un sinonim care nu e în
 * lista de aliasuri a `ImportField`-ului corespunzător, ACEST test pică, nu reimportul
 * tăcut al unui client peste trei luni.
 *
 * Testul acoperă doar cele două resurse care sunt și exportabile, și importabile
 * (accounts, contacts). Comenzile și facturile se exportă, dar nu se importă — pentru ele
 * nu există constrângerea de potrivire.
 *
 * Nota tehnică ce face totul să funcționeze: `ColumnMappingSuggester::normalize()` face
 * `preg_replace('/[^a-z0-9]+/', '', strtolower($v))`, deci elimină diacriticele de pe
 * ambele părți — „Prénom" (antet) și „prénom" (alias) ajung amândouă la `prnom`. Potrivirea
 * NU depinde de identitatea exactă a șirurilor, ci de forma lor normalizată.
 */
class ExportHeaderRoundTripTest extends TestCase
{
    /**
     * `ColumnMappingSuggester::suggest()` întoarce o LISTĂ de
     * `{header, field, confidence, score}` — o reindexez pe antet, ca aserțiunile de mai
     * jos să se citească drept „antetul X trimite la cheia Y".
     *
     * @param  list<array{header: string, field: string|null, confidence: string, score: float}>  $suggestions
     * @return array<string, string|null>
     */
    private function byHeader(array $suggestions): array
    {
        return array_combine(
            array_column($suggestions, 'header'),
            array_column($suggestions, 'field'),
        );
    }

    /**
     * @return list<string>
     */
    private function headersInFrench(callable $produce): array
    {
        $previous = app()->getLocale();
        app()->setLocale('fr');

        try {
            return $produce();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function test_french_account_export_headers_map_back_to_stable_keys(): void
    {
        $headers = $this->headersInFrench(fn () => (new AccountList)->exportHeaders());

        // Traducerea chiar s-a aplicat — altfel testul ar trece degeaba pe antete engleze.
        $this->assertContains('Nom', $headers);
        $this->assertContains('Domaine', $headers);

        $map = $this->byHeader(ColumnMappingSuggester::suggest($headers, (new AccountImportResource)->fields()));

        $this->assertSame('name', $map['Nom'] ?? null);
        $this->assertSame('domain', $map['Domaine'] ?? null);
        $this->assertSame('industry', $map['Secteur'] ?? null);
    }

    public function test_french_contact_export_headers_map_back_to_stable_keys(): void
    {
        $headers = $this->headersInFrench(fn () => (new ContactList)->exportHeaders());

        $this->assertContains('Prénom', $headers);
        $this->assertContains('Téléphone', $headers);

        $map = $this->byHeader(ColumnMappingSuggester::suggest($headers, (new ContactImportResource)->fields()));

        $this->assertSame('first_name', $map['Prénom'] ?? null);
        $this->assertSame('last_name', $map['Nom'] ?? null);
        $this->assertSame('email', $map['E-mail'] ?? null);
        $this->assertSame('phone', $map['Téléphone'] ?? null);
        $this->assertSame('title', $map['Poste'] ?? null);
        $this->assertSame('account_name', $map['Société'] ?? null);
    }

    public function test_english_export_headers_still_map_back_unchanged(): void
    {
        // Regresie: engleza rămâne implicit, deci exportul englez trebuie să se reimporte
        // exact ca înainte de acest lot — traducerea nu are voie să rupă calea existentă.
        $headers = (new AccountList)->exportHeaders();

        $this->assertContains('Name', $headers);

        $map = $this->byHeader(ColumnMappingSuggester::suggest($headers, (new AccountImportResource)->fields()));

        $this->assertSame('name', $map['Name'] ?? null);
        $this->assertSame('domain', $map['Domain'] ?? null);
    }
}
