<?php

namespace Tests\Feature\I18n;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * specs.md §15.8 FR-I18N-02 — nu „acoperirea" propriu-zisă (asta se verifică simetric pe
 * cheile `en`/`fr`, prin comanda de acoperire rulată în CI, pe cataloagele REALE) ci PLASA
 * tehnică de dedesubt, citată verbatim în cerință: „o cheie lipsă NU cade tăcut pe
 * fallback-ul engleză în producție: fallback-ul rămâne ca plasă tehnică, dar apariția lui
 * la runtime înseamnă că testul de acoperire a picat și n-a fost observat". Cu alte
 * cuvinte: DACĂ testul de acoperire ar rata o gaură, utilizatorul tot n-ar trebui să vadă
 * niciodată `some.missing.key` brut pe ecran — ar trebui să vadă engleza.
 *
 * NOTĂ pentru cititor, cerută explicit de task: `plan-implementare.md` glosează
 * `BR-I18N-01` drept „fallback la cheie lipsă", dar `specs.md` — sursa normativă — definește
 * `BR-I18N-01` ca fiind maparea de coloane la import pe cheie stabilă (§15.8, acoperită de
 * `tests/Feature/Imports/FrenchHeaderAliasRoundTripTest.php` și
 * `ExportHeaderRoundTripTest.php`, NEATINSE de acest fișier). Testul de față verifică
 * FR-I18N-02, nu BR-I18N-01 — numit după identificatorul corect, ca să nu apară o a doua
 * definiție de BR-I18N-01 în suită.
 *
 * Niciun fișier din `lang/fr/*.php` nu e atins aici — dacă o cheie reală ar lipsi acolo,
 * testul care ar trebui să pice e cel de acoperire, nu acesta. Gaura e SIMULATĂ prin
 * `Lang::addLines()`, care scrie direct în cache-ul intern al `Translator`-ului (ocolind
 * loader-ul de fișiere) — exact mecanismul necesar ca să observăm „ce s-ar întâmpla DACĂ o
 * cheie ar lipsi", fără să atingem catalogul real.
 */
class MissingKeyFallbackTest extends TestCase
{
    /**
     * Premisa fără de care mecanismul de fallback n-are pe ce să cadă. Citit din `config()`,
     * nu din `.env` direct — asta e valoarea pe care `Translator::$fallback` o folosește
     * efectiv la runtime.
     */
    public function test_the_application_fallback_locale_is_english(): void
    {
        $this->assertSame('en', config('app.fallback_locale'));
    }

    /**
     * Stratul Laravel — o cheie prezentă DOAR în engleză (simulată, nu un gol real din
     * `lang/fr/`) se rezolvă la textul englezesc când locale-ul curent e franceza, nu la
     * cheia brută și nu la o excepție.
     */
    public function test_a_key_present_only_in_english_resolves_to_english_text_when_locale_is_french(): void
    {
        Lang::addLines(['i18n_fallback_probe.only_in_english' => 'Probe text in English'], 'en');

        App::setLocale('fr');

        $this->assertSame('Probe text in English', __('i18n_fallback_probe.only_in_english'));
    }

    /**
     * Cazul urât, cerut explicit de task: o cheie absentă din AMBELE limbi. Laravel n-are pe
     * ce să cadă — `Translator::get()` întoarce cheia brută, verbatim. Asta e o CONSTATARE,
     * nu un eșec al acestui test: comportamentul e cel documentat al framework-ului, nu un
     * bug al aplicației. În producție, o cheie complet absentă ar produce text vizibil de
     * forma `pachet.cheie.care.nu.exista` pe ecran — exact simptomul pe care testul de
     * ACOPERIRE din CI trebuie să-l prindă înainte de merge. Testul de față demonstrează
     * doar că plasa de fallback pe englez NU acoperă și acest caz — nu există o a doua plasă
     * dedesubt.
     */
    public function test_a_key_missing_from_both_locales_falls_back_to_the_raw_key(): void
    {
        App::setLocale('fr');

        $this->assertSame(
            'i18n_fallback_probe.missing_everywhere',
            __('i18n_fallback_probe.missing_everywhere'),
        );
    }

    /**
     * Stratul i18next (frontend) — verificare SLABĂ, asumată ca atare în task: proiectul NU
     * are vitest și nu avem voie să adăugăm unul, deci nu putem dovedi din Pest ce randează
     * efectiv biblioteca JS la runtime pentru o cheie lipsă. Ce PUTEM verifica e că sursa de
     * bootstrap chiar declară `fallbackLng: 'en'` — dacă cineva șterge sau schimbă acea
     * linie, testul pică, deși nu dovedește comportamentul real din browser.
     *
     * `resources/js/lib/i18n.ts` documentează el însuși limita simetrică pe partea lui:
     * fără `parseMissingKeyHandler`, o cheie absentă din AMBELE cataloage cade pe cheia
     * brută (comportamentul implicit al bibliotecii) — acoperirea reală, blocantă, vine din
     * `php artisan i18n:coverage` (rulat în CI), nu dintr-un fallback tăcut în acest fișier.
     * Aceeași asimetrie ca la Laravel: fallback pe engleză pentru „lipsește dintr-o parte",
     * fără plasă pentru „lipsește din ambele părți".
     */
    public function test_the_frontend_i18n_bootstrap_declares_an_english_fallback_language(): void
    {
        $source = file_get_contents(resource_path('js/lib/i18n.ts'));

        $this->assertIsString($source);
        $this->assertStringContainsString(
            "fallbackLng: 'en'",
            $source,
            "resources/js/lib/i18n.ts trebuie să declare fallbackLng: 'en' — fără el, o cheie "
            .'lipsă din franceză ar cădea pe cheia brută în loc de engleză, la runtime.',
        );
    }
}
