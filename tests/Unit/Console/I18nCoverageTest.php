<?php

namespace Tests\Unit\Console;

use App\Console\Commands\I18nCoverage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * TEST-11 (audit 2026-09-23, §11-teste.md) — `i18n:coverage` e un gate blocant în CI
 * (`.ai/rules/project.md`, jobul `quality`), fără niciun test al logicii de detecție.
 *
 * Fără bază de date (`PHPUnit\Framework\TestCase`, nu `Tests\TestCase`) — pe modelul
 * `ArchitectureTest`/`ListQueryTest`: logica verificată aici e citire de fișiere și
 * comparație de mulțimi de chei, nimic din ea nu atinge PostgreSQL.
 *
 * ## Limita de injectare a căilor — GĂSITĂ, nu presupusă
 *
 * `I18nCoverage::handle()` construiește array-ul `$layers` cu rădăcinile HARDCODATE
 * `base_path('lang')` și `resource_path('js/locales')`, direct în corpul metodei — fără
 * opțiune de linie de comandă, fără citire din `config()`, fără parametru. `app()->
 * useLangPath()` (mecanismul nativ Laravel pentru un lang path alternativ) NU ajută:
 * comanda nu-l citește deloc, `base_path('lang')` ignoră orice `useLangPath()` ar seta.
 * Nu există niciun echivalent pentru `resource_path()`.
 *
 * Consecința: un test care rulează comanda END-TO-END (`Artisan::call('i18n:coverage')`)
 * cu cataloage SINTETICE, fără să scrie în `lang/`/`resources/js/locales/` reale, nu e
 * posibil azi. Schimbarea minimă (neaplicată — `app/**` nu e în fișierele acestei
 * sesiuni): extrage cele două rădăcini în două metode `protected` (ex. `langRoot(): string`
 * / `jsLocalesRoot(): string`), cu implicitul de azi (`base_path('lang')`/
 * `resource_path('js/locales')`), suprascriptibile dintr-o subclasă de test SAU citite
 * dintr-o cheie nouă de config (`config('i18n.lang_root')`) cu același implicit — oricare
 * din cele două ar permite un `Artisan::call()` real pe un director temporar, fără să
 * schimbe comportamentul de producție (config-ul implicit rămâne identic cu hardcodarea
 * de azi).
 *
 * ## Ce se testează în schimb
 *
 * TOATE metodele `private` care fac detecția propriu-zisă (`layerExists`, `pairsFor`,
 * `loadCatalog`, `findRelativeFiles`, `flattenKeys`, `expectedKeysFor`, `pluralBase`,
 * `pluralHint`) primesc rădăcina de citit ca PARAMETRU (`$layer['root']`/`$path`), nu
 * hardcodat — deci sunt testabile prin Reflection cu un `$layer` sintetic care indică spre
 * un director temporar, FĂRĂ să atingă `lang/`/`resources/js/locales/` reale. Testele de
 * mai jos apelează exact aceste metode, în aceeași ordine în care `handle()` le apelează
 * (`runLayer()` mai jos oglindește bucla din `handle()` linie cu linie, DOAR pentru
 * orchestrarea de nivel-superior — fiecare calcul individual rulează prin metoda privată
 * reală a comenzii, prin Reflection, nu reimplementat). Golul rămas — pornirea comenzii
 * complet, cu ieșirea Console reală — e acoperit separat, pe cataloagele REALE ale
 * repo-ului, de `tests/Feature/Console/I18nCoverageGateTest.php`.
 *
 * ## Goluri de acoperire GĂSITE (nu bug-uri de reparat — raportate, nu corectate aici)
 *
 * Comanda compară DOAR mulțimi de chei (`flattenKeys()` ignoră valoarea, reține doar
 * calea). Nu detectează: o VALOARE goală prezentă în ambele limbi (`test_...empty_value`,
 * mai jos) și un PLACEHOLDER (`:name`) diferit între traduceri
 * (`test_...mismatched_placeholder`). Ambele treceau verzi azi — testele de mai jos le
 * îngheață explicit, ca gaura să fie vizibilă, nu presupusă.
 */
class I18nCoverageTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Logică pură (fără disc): expectedKeysFor / pluralBase / pluralHint / flattenKeys
    // ------------------------------------------------------------------

    public function test_flatten_keys_produces_dot_paths_for_nested_arrays(): void
    {
        $keys = $this->invoke('flattenKeys', [[
            'flash' => ['products' => ['created' => 'Created', 'updated' => 'Updated']],
            'top_level' => 'Value',
        ]]);

        sort($keys);
        $this->assertSame(['flash.products.created', 'flash.products.updated', 'top_level'], $keys);
    }

    /**
     * Docblock-ul clasei, `expectedKeysFor()`: un `_other` prezent în ORICARE limbă e
     * semnalul unei chei plurale; fără el, un `foo_one` singuratic rămâne o cheie
     * obișnuită, nu declanșează categorii inventate (`foo_many`/`foo_other`).
     */
    public function test_a_lone_one_suffixed_key_without_other_is_not_treated_as_plural(): void
    {
        $expected = $this->invoke('expectedKeysFor', ['fr', ['chosen_one'], ['chosen_one']]);

        $this->assertSame(['chosen_one'], $expected);
    }

    /**
     * Franceza cere `one`/`many`/`other`; engleza doar `one`/`other` — măsurat pe
     * `Intl.PluralRules`, transcris în `PLURAL_CATEGORIES`. O bază pluralizată (semnalată
     * de `_other`, prezent aici doar în `fr`) trebuie expandată pe categoriile CERUTE DE
     * FIECARE limbă, nu pe o mulțime comună.
     */
    public function test_expected_keys_for_expands_the_plural_categories_required_by_each_locale(): void
    {
        $sourceKeys = ['app.title'];
        $targetKeys = ['app.title', 'rows_one', 'rows_other'];

        $expectedFr = $this->invoke('expectedKeysFor', ['fr', $sourceKeys, $targetKeys]);
        $expectedEn = $this->invoke('expectedKeysFor', ['en', $sourceKeys, $targetKeys]);

        $this->assertSame(['app.title', 'rows_many', 'rows_one', 'rows_other'], $expectedFr);
        $this->assertSame(['app.title', 'rows_one', 'rows_other'], $expectedEn, 'Engleza n-are categoria CLDR "many" — n-ar trebui s-o ceară.');
    }

    public function test_plural_base_extracts_the_key_without_its_cldr_suffix(): void
    {
        $this->assertSame('rows', $this->invoke('pluralBase', ['rows_many']));
        $this->assertNull($this->invoke('pluralBase', ['welcome.title']));
    }

    public function test_plural_hint_explains_a_missing_cldr_category_and_is_empty_otherwise(): void
    {
        $hint = $this->invoke('pluralHint', ['rows_many', 'fr']);
        $this->assertStringContainsString('many', $hint);
        $this->assertStringContainsString('fr', $hint);

        $this->assertSame('', $this->invoke('pluralHint', ['welcome.title', 'fr']));
    }

    // ------------------------------------------------------------------
    // Citire de disc: loadCatalog (php ȘI json) / findRelativeFiles / pairsFor / layerExists
    // ------------------------------------------------------------------

    public function test_load_catalog_parses_a_php_lang_file_via_require(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/messages.php', "<?php\nreturn ['welcome' => 'Hello'];\n");

        $data = $this->invoke('loadCatalog', [$dir.'/messages.php', false]);

        $this->assertSame(['welcome' => 'Hello'], $data);
    }

    public function test_load_catalog_parses_a_json_file_via_json_decode(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/common.json', json_encode(['welcome' => 'Hello'], JSON_THROW_ON_ERROR));

        $data = $this->invoke('loadCatalog', [$dir.'/common.json', true]);

        $this->assertSame(['welcome' => 'Hello'], $data);
    }

    /**
     * Docblock-ul `findRelativeFiles()`: recursiv, „ca `lang/en/emails/order.php` să nu
     * fie ratat" — verificat cu un fișier țintă la 2 niveluri adâncime.
     */
    public function test_find_relative_files_recurses_into_subdirectories(): void
    {
        $root = $this->tempDir();
        mkdir($root.'/emails', 0755, true);
        file_put_contents($root.'/messages.php', '<?php return [];');
        file_put_contents($root.'/emails/order.php', '<?php return [];');
        file_put_contents($root.'/notes.txt', 'ignored — nu se potrivește cu *.php');

        $found = $this->invoke('findRelativeFiles', [$root, '*.php']);

        $this->assertSame(['emails/order.php', 'messages.php'], $found);
    }

    /**
     * Un fișier PREZENT DOAR pe o parte (namespace întreg lipsă în cealaltă limbă) — docblock-ul
     * `handle()`: „nu are nevoie de caz special: toate cheile lui ies ca asimetrie". `pairsFor()`
     * uneș te fișierele găsite în AMBELE rădăcini, deci relativul apare chiar dacă `fr/` nu-l are.
     */
    public function test_pairs_for_a_directory_layer_unions_files_present_on_only_one_side(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        mkdir($layer['root'].'/en', 0755, true);
        mkdir($layer['root'].'/fr', 0755, true);
        file_put_contents($layer['root'].'/en/emails.php', "<?php\nreturn ['subject' => 'Hello'];\n");
        // `fr/emails.php` lipsește complet.

        $pairs = $this->invoke('pairsFor', [$layer]);

        $this->assertArrayHasKey('emails.php', $pairs);
        $this->assertFileDoesNotExist($pairs['emails.php']['target']);
    }

    public function test_pairs_for_a_locale_file_layer_points_at_en_json_and_fr_json(): void
    {
        $layer = $this->localeFileLayer();

        $pairs = $this->invoke('pairsFor', [$layer]);
        $label = array_key_first($pairs);

        $this->assertStringContainsString('en.json', $label);
        $this->assertStringContainsString('fr.json', $label);
        $this->assertSame($layer['root'].'/en.json', $pairs[$label]['source']);
        $this->assertSame($layer['root'].'/fr.json', $pairs[$label]['target']);
    }

    /** Docblock-ul clasei comandă: „grațioasă pe cataloage absente, deliberat". */
    public function test_layer_exists_is_false_when_neither_locale_root_exists(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        // Niciun subdirector `en/`/`fr/` creat sub `$layer['root']`.

        $this->assertFalse($this->invoke('layerExists', [$layer]));
    }

    public function test_layer_exists_is_true_when_at_least_one_locale_root_exists(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        mkdir($layer['root'].'/en', 0755, true);

        $this->assertTrue($this->invoke('layerExists', [$layer]));
    }

    // ------------------------------------------------------------------
    // Simulare de gate, pe cataloage SINTETICE — dovedește pică/trece cu metodele REALE
    // ------------------------------------------------------------------

    public function test_gate_fails_when_a_key_is_missing_in_the_target_language(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        $this->writePhp($layer['root'].'/en/messages.php', ['welcome' => 'Hello', 'goodbye' => 'Goodbye']);
        $this->writePhp($layer['root'].'/fr/messages.php', ['welcome' => 'Bonjour']);

        $result = $this->runLayer($layer);

        $this->assertTrue($result['hasFailure']);
        $this->assertSame(['goodbye'], $result['files']['messages.php']['missingInTarget']);
    }

    public function test_gate_fails_when_the_target_language_has_an_orphan_key(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        $this->writePhp($layer['root'].'/en/messages.php', ['a' => 'A']);
        $this->writePhp($layer['root'].'/fr/messages.php', ['a' => 'A', 'b' => 'B']);

        $result = $this->runLayer($layer);

        $this->assertTrue($result['hasFailure']);
        $this->assertSame(['b'], $result['files']['messages.php']['missingInSource'], '"b" n-are pereche în en — semnalat ca "lipsă în en".');
    }

    public function test_gate_fails_on_the_locale_file_shape_with_a_php_style_message(): void
    {
        $layer = $this->localeFileLayer();
        file_put_contents($layer['root'].'/en.json', json_encode(['Not Found' => 'Not Found', 'Hello' => 'Hello'], JSON_THROW_ON_ERROR));
        file_put_contents($layer['root'].'/fr.json', json_encode(['Not Found' => 'Introuvable'], JSON_THROW_ON_ERROR));

        $result = $this->runLayer($layer);
        $label = array_key_first($result['files']);

        $this->assertTrue($result['hasFailure']);
        $this->assertSame(['Hello'], $result['files'][$label]['missingInTarget']);
    }

    /**
     * Stratul i18next (CLDR): `rows_many` există în `en` (via `_other`) dar lipsește complet
     * din `fr` — categoria e OBLIGATORIE pentru franceză (`PLURAL_CATEGORIES['fr']`), deci
     * trebuie semnalată, deși `rows_many` nu apare nicăieri, în NICIO limbă, ca literal.
     */
    public function test_gate_fails_when_the_french_plural_category_many_is_missing(): void
    {
        $layer = $this->directoryLayer('*.json', 'json', true);
        $this->writeJson($layer['root'].'/en/common.json', ['app' => ['title' => 'App'], 'rows_one' => '1 row', 'rows_other' => '{{count}} rows']);
        $this->writeJson($layer['root'].'/fr/common.json', ['app' => ['title' => 'Appli'], 'rows_one' => '1 ligne', 'rows_other' => '{{count}} lignes']);

        $result = $this->runLayer($layer);

        $this->assertTrue($result['hasFailure']);
        $this->assertSame(['rows_many'], $result['files']['common.json']['missingInTarget']);
    }

    public function test_gate_passes_once_the_french_plural_category_many_is_added(): void
    {
        $layer = $this->directoryLayer('*.json', 'json', true);
        $this->writeJson($layer['root'].'/en/common.json', ['rows_one' => '1 row', 'rows_other' => '{{count}} rows']);
        $this->writeJson($layer['root'].'/fr/common.json', ['rows_one' => '1 ligne', 'rows_many' => '{{count}} lignes (many)', 'rows_other' => '{{count}} lignes']);

        $result = $this->runLayer($layer);

        $this->assertFalse($result['hasFailure']);
    }

    public function test_gate_passes_on_balanced_catalogs_across_all_three_layer_shapes(): void
    {
        $php = $this->directoryLayer('*.php', 'php', false);
        $this->writePhp($php['root'].'/en/messages.php', ['welcome' => ['title' => 'Welcome']]);
        $this->writePhp($php['root'].'/fr/messages.php', ['welcome' => ['title' => 'Bienvenue']]);

        $localeFile = $this->localeFileLayer();
        file_put_contents($localeFile['root'].'/en.json', json_encode(['Not Found' => 'Not Found'], JSON_THROW_ON_ERROR));
        file_put_contents($localeFile['root'].'/fr.json', json_encode(['Not Found' => 'Introuvable'], JSON_THROW_ON_ERROR));

        $cldr = $this->directoryLayer('*.json', 'json', true);
        $this->writeJson($cldr['root'].'/en/common.json', ['rows_one' => '1 row', 'rows_other' => '{{count}} rows']);
        $this->writeJson($cldr['root'].'/fr/common.json', ['rows_one' => '1 ligne', 'rows_many' => 'many', 'rows_other' => '{{count}} lignes']);

        foreach ([$php, $localeFile, $cldr] as $layer) {
            $result = $this->runLayer($layer);
            $this->assertFalse($result['hasFailure'], "Stratul \"{$layer['root']}\" ar fi trebuit să treacă echilibrat.");
        }
    }

    // ------------------------------------------------------------------
    // Goluri de acoperire GĂSITE — comportamentul de azi, înghețat (nu bug-uri de reparat)
    // ------------------------------------------------------------------

    /**
     * `flattenKeys()` reține doar CALEA, nu valoarea — o traducere PREZENTĂ dar goală
     * (`""`) e o cheie ca oricare alta pentru comparație, deci gate-ul NU o prinde. Raportat
     * ca gol de acoperire, nu reparat aici (`app/**` nu e în fișierele acestei sesiuni).
     */
    public function test_the_gate_does_not_detect_an_empty_translation_value(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        $this->writePhp($layer['root'].'/en/messages.php', ['greeting' => 'Hello']);
        $this->writePhp($layer['root'].'/fr/messages.php', ['greeting' => '']);

        $result = $this->runLayer($layer);

        $this->assertFalse($result['hasFailure'], 'Comportament curent — golul e documentat, nu corectat aici.');
    }

    /**
     * Un placeholder Laravel (`:name`) tradus greșit (`:nom`, alt nume) e tot o cheie
     * prezentă în ambele limbi — gate-ul compară mulțimi de CHEI, niciodată conținutul
     * interpolabil al valorii, deci un `:name` uitat netradus corect trece verde.
     */
    public function test_the_gate_does_not_detect_a_mismatched_placeholder(): void
    {
        $layer = $this->directoryLayer('*.php', 'php', false);
        $this->writePhp($layer['root'].'/en/messages.php', ['welcome' => 'Hello :name']);
        $this->writePhp($layer['root'].'/fr/messages.php', ['welcome' => 'Bonjour :nom']);

        $result = $this->runLayer($layer);

        $this->assertFalse($result['hasFailure'], 'Comportament curent — golul e documentat, nu corectat aici.');
    }

    // ------------------------------------------------------------------
    // Infrastructură de test
    // ------------------------------------------------------------------

    /**
     * Oglinda EXACTĂ a buclei per-strat din `I18nCoverage::handle()` (liniile care produc
     * `$missingInTarget`/`$missingInSource`/`$orphanInTarget`/`$orphanInSource` și
     * `$hasFailure`) — dar fiecare calcul individual trece prin METODA PRIVATĂ REALĂ a
     * comenzii, via Reflection, nu reimplementat. Singura logică NEDUPLICATĂ de o metodă a
     * clasei e orchestrarea de nivel-superior (bucla + agregarea `hasFailure`), inevitabilă
     * cât timp `handle()` n-are un punct de injectare a rădăcinilor (vezi docblock-ul clasei).
     *
     * @param  array<string, mixed>  $layer
     * @return array{hasFailure: bool, files: array<string, array{missingInTarget: list<string>, missingInSource: list<string>, orphanInTarget: list<string>, orphanInSource: list<string>}>}
     */
    private function runLayer(array $layer): array
    {
        if (! $this->invoke('layerExists', [$layer])) {
            return ['hasFailure' => false, 'files' => []];
        }

        $pairs = $this->invoke('pairsFor', [$layer]);
        $hasFailure = false;
        $files = [];

        foreach ($pairs as $relative => $paths) {
            $isJson = $layer['parse'] === 'json';

            $sourceKeys = is_file($paths['source']) ? $this->invoke('flattenKeys', [$this->invoke('loadCatalog', [$paths['source'], $isJson])]) : [];
            $targetKeys = is_file($paths['target']) ? $this->invoke('flattenKeys', [$this->invoke('loadCatalog', [$paths['target'], $isJson])]) : [];

            $expectedSource = $layer['cldr'] ? $this->invoke('expectedKeysFor', ['en', $sourceKeys, $targetKeys]) : $targetKeys;
            $expectedTarget = $layer['cldr'] ? $this->invoke('expectedKeysFor', ['fr', $sourceKeys, $targetKeys]) : $sourceKeys;

            $missingInTarget = array_values(array_diff($expectedTarget, $targetKeys));
            $missingInSource = array_values(array_diff($expectedSource, $sourceKeys));
            $orphanInTarget = array_values(array_diff($targetKeys, $expectedTarget));
            $orphanInSource = array_values(array_diff($sourceKeys, $expectedSource));

            if ($missingInTarget !== [] || $missingInSource !== [] || $orphanInTarget !== [] || $orphanInSource !== []) {
                $hasFailure = true;
            }

            $files[$relative] = compact('missingInTarget', 'missingInSource', 'orphanInTarget', 'orphanInSource');
        }

        return ['hasFailure' => $hasFailure, 'files' => $files];
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod(I18nCoverage::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new I18nCoverage, ...$args);
    }

    /** @return array{shape: string, root: string, pattern: string, parse: string, cldr: bool} */
    private function directoryLayer(string $pattern, string $parse, bool $cldr): array
    {
        return ['shape' => 'directory', 'root' => $this->tempDir(), 'pattern' => $pattern, 'parse' => $parse, 'cldr' => $cldr];
    }

    /** @return array{shape: string, root: string, parse: string, cldr: bool} */
    private function localeFileLayer(): array
    {
        return ['shape' => 'locale_file', 'root' => $this->tempDir(), 'parse' => 'json', 'cldr' => false];
    }

    /** @param  array<array-key, mixed>  $data */
    private function writePhp(string $path, array $data): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, '<?php'.PHP_EOL.'return '.var_export($data, true).';'.PHP_EOL);
    }

    /** @param  array<array-key, mixed>  $data */
    private function writeJson(string $path, array $data): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/t6-i18n-coverage-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
