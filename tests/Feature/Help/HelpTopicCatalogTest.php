<?php

namespace Tests\Feature\Help;

use Tests\TestCase;

/**
 * Garda introdusă de Valul 4 al Lotului I18N (ADR-022, FR-I18N-02/FR-HELP-01).
 *
 * **Modul de eșec pe care îl acoperă, și de ce nu-l acoperea nimic.** Până la acest val,
 * un subiect de ajutor era un singur obiect: structura și textul nu puteau diverge,
 * fiindcă erau aceeași literă din același fișier. Valul 4 le-a despărțit — structura
 * rămâne în `resources/js/help/topics/*.ts` (`id` + blocul `adr`), textul a plecat în
 * `resources/js/locales/{en,fr}/help.json`. De aici încolo, un subiect nou adăugat la
 * hartă fără intrare în catalog randează un panou GOL: titlu vid, zero puncte, zero
 * reguli. TypeScript compilează, `HelpTopicCoverageTest` trece (harta ARE cheia), iar
 * `php artisan i18n:coverage` trece la fel (cataloagele `en` și `fr` sunt simetric
 * incomplete — le lipsește aceeași intrare).
 *
 * Deci exact tăcerea pe care FR-I18N-02 o interzice, doar că mutată cu un nivel mai sus:
 * nu „o cheie lipsă din `fr`", ci „un subiect întreg lipsă din ambele".
 *
 * Parsare regex a fișierelor `.ts`, ca în `HelpTopicCoverageTest` și
 * `HelpTopicAdrLinkTest`, din același motiv: Pest rulează în PHP și nu poate `import`
 * un modul TypeScript.
 */
class HelpTopicCatalogTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TEXT_FIELDS = ['title', 'whatIsThis', 'howItsBuilt'];

    /** @var list<string> */
    private const REQUIRED_LIST_FIELDS = ['whatCanYouDo', 'rules'];

    /** @var list<string> */
    private const LOCALES = ['en', 'fr'];

    public function test_every_topic_definition_has_complete_content_in_every_locale(): void
    {
        $ids = $this->definitionIds();

        $this->assertGreaterThanOrEqual(
            30,
            count($ids),
            'Prea puține definiții de subiect parsate din resources/js/help/topics/ — regexul a ieșit din sincron cu formatul fișierelor sursă.'
        );

        foreach (self::LOCALES as $locale) {
            $catalog = $this->catalog($locale);

            foreach ($ids as $id) {
                $this->assertArrayHasKey(
                    $id,
                    $catalog,
                    sprintf(
                        'Subiectul "%s" e mapat în resources/js/help/index.ts, dar nu are text în resources/js/locales/%s/help.json — panoul s-ar randa gol pe limba „%s" (FR-HELP-01).',
                        $id,
                        $locale,
                        $locale
                    )
                );

                $topic = $catalog[$id];

                foreach (self::REQUIRED_TEXT_FIELDS as $field) {
                    $this->assertIsString($topic[$field] ?? null, "{$locale}/help.json → {$id}.{$field} lipsește sau nu e text.");
                    $this->assertNotSame('', trim($topic[$field]), "{$locale}/help.json → {$id}.{$field} e gol.");
                }

                foreach (self::REQUIRED_LIST_FIELDS as $field) {
                    $this->assertIsArray($topic[$field] ?? null, "{$locale}/help.json → {$id}.{$field} lipsește sau nu e listă.");
                    $this->assertNotEmpty($topic[$field], "{$locale}/help.json → {$id}.{$field} e o listă goală (FR-HELP-02 cere conținut pe toate cele patru părți).");
                }
            }

            // Sensul invers: o intrare rămasă în catalog după ce subiectul a fost scos din
            // hartă e text mort, tradus și întreținut degeaba.
            foreach (array_keys($catalog) as $id) {
                $this->assertContains(
                    $id,
                    $ids,
                    sprintf('resources/js/locales/%s/help.json conține subiectul "%s", care nu mai e mapat în resources/js/help/index.ts.', $locale, $id)
                );
            }
        }
    }

    /**
     * Identificatorii subiectelor, citiți din DEFINIȚII, nu din catalog — sursa de adevăr
     * pentru „ce subiecte există" e codul, nu textul.
     *
     * @return list<string>
     */
    private function definitionIds(): array
    {
        $ids = [];

        foreach (glob(base_path('resources/js/help/topics/*.ts')) as $file) {
            if (preg_match('/^\s{4}id: \'([^\']+)\',$/m', (string) file_get_contents($file), $match) === 1) {
                $ids[] = $match[1];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(string $locale): array
    {
        $path = base_path("resources/js/locales/{$locale}/help.json");

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && is_array($decoded['topics'] ?? null) ? $decoded['topics'] : [];
    }
}
