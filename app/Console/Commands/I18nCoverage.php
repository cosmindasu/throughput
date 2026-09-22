<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * FR-I18N-02, ADR-022 — acoperirea cheilor de traducere, pe modelul lui
 * `HelpTopicCoverageTest` (FR-HELP-04): o cheie lipsă NU cade tăcut pe fallback-ul
 * englez în producție, pică verificarea automat.
 *
 * Construită la Valul 1 al Lotului I18N, deși rodește abia la Valul 4, când cataloagele
 * ajung complete — o gardă adăugată după ce golurile există e o gardă care nu a apucat
 * să prevină nimic.
 *
 * Verifică SIMETRIC, pe TREI straturi independente, pentru că textul aplicației trăiește pe
 * trei rânduri de randare diferite:
 *   - `lang/{en,fr}/*.php` — mesajele Laravel pe grup+cheie (flash, validare, email, PDF);
 *   - `lang/{en,fr}.json` — mesajele Laravel în care ȘIRUL ENGLEZ E CHEIA (`__('Not Found')`),
 *     singura formă pe care o folosesc vederile de eroare ale framework-ului;
 *   - `resources/js/locales/{en,fr}/*.json` — cataloagele `i18next` (etichete UI).
 *
 * Al treilea strat a fost adăugat după ce s-a văzut că lipsea: vederile de eroare
 * (403/404/419/429/500/503) randau opt șiruri engleze pe interfața franceză, iar comanda
 * asta nu se uita deloc acolo. Un catalog nepăzit e exact ce FR-I18N-02 interzice, deci
 * gate-ul a fost lărgit ÎNAINTE de a fi populat — nu invers.
 *
 * `lang/en.json` e IDENTITATE (`"Not Found": "Not Found"`) și e deliberat, nu redundanță:
 * pe stratul ăsta cheia E șirul englez, deci fără fișierul sursă comanda n-ar avea cu ce
 * compara franceza, iar modelul „`en` = sursă de adevăr" s-ar rupe pentru un singur strat.
 * Laravel se comportă identic cu sau fără el (o cheie negăsită se întoarce pe sine), deci
 * fișierul nu schimbă nimic la randare — există ca să facă mulțimea de șiruri VERIFICABILĂ.
 * Alternativa luată în calcul și respinsă: să se extragă șirurile din sursă cu un regex peste
 * `__('…')`, ca în `HelpTopicCoverageTest`. Ar prinde și șirurile folosite dar netraduse, dar
 * ar trebui să citească și `vendor/`, unde stau chiar vederile de eroare — prea multă
 * fragilitate pentru opt șiruri.
 *
 * Simetric înseamnă în ambele sensuri: o cheie în `en` fără pereche în `fr` e o
 * traducere uitată; una în `fr` fără pereche în `en` e o cheie moartă, rămasă după o
 * redenumire. Ambele pică.
 *
 * **Grațioasă pe cataloage absente, deliberat.** La Valul 1 cataloagele încă nu există;
 * un strat fără rădăcină pe disc e sărit, nu eșuat. Altfel gate-ul ar fi roșu din prima
 * zi, iar un gate mereu roșu se dezactivează, nu se respectă.
 *
 * Citește `lang/*.php` prin `require`, nu prin regex peste sursă: fișierele sunt
 * `<?php return [...];`, deci parserul real al PHP le dă exact array-ul pe care l-ar
 * citi și Laravel — array-uri nested, comentarii și virgule în plus, gratuit.
 *
 * Rulează ca prim pas în jobul `quality` din CI, imediat după `composer install` și
 * ÎNAINTE de bootstrap-ul Postgres și de suita Pest: o cheie desperecheată oprește
 * pipeline-ul înainte să ardă ~15 minute de teste, pe o cotă de minute partajată cu
 * alte proiecte.
 *
 * ## Pluralizarea nu e simetrică între limbi (adăugat la Valul 3)
 *
 * Engleza are DOUĂ categorii CLDR (`one`, `other`); franceza are TREI (`one`, `many`,
 * `other`), unde `many` se aplică milioanelor exacte. i18next își alege sufixul cheii
 * prin `Intl.PluralRules`, iar o categorie lipsă **nu** cade pe `_other` din aceeași
 * limbă: cade pe `fallbackLng`. Măsurat, nu presupus — cu `rows_one`/`rows_other` scrise
 * în franceză și fără `rows_many`, `t('rows', { count: 1000000 })` întoarce textul
 * ENGLEZESC, într-o interfață altfel complet franceză.
 *
 * Comparația naivă pe mulțimi de chei nu putea vedea asta: `rows_many` lipsea din AMBELE
 * cataloage, deci ieșea simetrică și trecea verde. Exact modul de eșec pe care
 * FR-I18N-02 îl descrie („o cheie lipsă nu cade tăcut pe fallback-ul engleză") — doar că
 * ascuns într-o categorie gramaticală pe care engleza nu o are.
 *
 * De aceea stratul JSON se compară pe **cheia de bază**, cu categoriile cerute de FIECARE
 * limbă, nu cheie-la-cheie. Un `x_many` prezent în `fr` și absent din `en` nu mai e o
 * orfană, e forma corectă; unul ABSENT din `fr` e acum o eroare, deși nimic nu lipsește
 * din `en`. Stratul `lang/` rămâne pe comparația directă: Laravel pluralizează prin
 * `trans_choice()` cu sintaxa de segmente (`{0}…|[1,*]…`) în interiorul UNEI chei, deci
 * nu există sufixe de categorie și nu există asimetrie de acoperit.
 */
class I18nCoverage extends Command
{
    protected $signature = 'i18n:coverage';

    protected $description = 'Check that translation keys match symmetrically between en and fr, on both the Laravel and i18next catalogs (FR-I18N-02)';

    private const SOURCE_LOCALE = 'en';

    private const TARGET_LOCALE = 'fr';

    /** Catalog împărțit pe DIRECTOARE per limbă: `<root>/<locale>/<fișiere>`. */
    private const SHAPE_DIRECTORY = 'directory';

    /** Catalog într-un SINGUR fișier per limbă, numit după limbă: `<root>/<locale>.json`. */
    private const SHAPE_LOCALE_FILE = 'locale_file';

    private const PARSE_PHP = 'php';

    private const PARSE_JSON = 'json';

    /**
     * Categoriile CLDR pe care i18next le cere pentru fiecare limbă, enumerate explicit
     * pentru CELE DOUĂ limbi ale proiectului. PHP nu expune categoriile plurale ale ICU
     * printr-un API direct (`MessageFormatter` le consumă, nu le listează), iar o listă
     * scrisă pentru exact limbile suportate e mai ușor de citit și de verificat decât o
     * derivare indirectă. O a treia limbă ar adăuga un rând aici — și ar trebui să-l
     * adauge, altfel ar moșteni tăcut regulile englezei.
     *
     * Sursa: `Intl.PluralRules(locale).resolvedOptions().pluralCategories`, verificată în
     * runtime-ul care rulează aplicația.
     *
     * @var array<string, list<string>>
     */
    private const PLURAL_CATEGORIES = [
        'en' => ['one', 'other'],
        'fr' => ['one', 'many', 'other'],
    ];

    /** Toate sufixele CLDR posibile, pentru a recunoaște o cheie plurală oriunde. */
    private const ALL_PLURAL_SUFFIXES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    public function handle(): int
    {
        /** @var array<string, array{shape: string, root: string, pattern?: string, parse: string, cldr: bool}> $layers */
        $layers = [
            'lang/{locale}/ (Laravel — flash, validare, email, PDF)' => [
                'shape' => self::SHAPE_DIRECTORY,
                'root' => base_path('lang'),
                'pattern' => '*.php',
                'parse' => self::PARSE_PHP,
                'cldr' => false,
            ],
            'lang/{locale}.json (Laravel — șiruri care sunt propria lor cheie)' => [
                'shape' => self::SHAPE_LOCALE_FILE,
                'root' => base_path('lang'),
                'parse' => self::PARSE_JSON,
                'cldr' => false,
            ],
            'resources/js/locales/{locale}/ (i18next — etichete UI)' => [
                'shape' => self::SHAPE_DIRECTORY,
                'root' => resource_path('js/locales'),
                'pattern' => '*.json',
                'parse' => self::PARSE_JSON,
                'cldr' => true,
            ],
        ];

        $hasFailure = false;
        $totalKeysChecked = 0;

        $this->line('Acoperire chei i18n ('.self::SOURCE_LOCALE.' = sursă de adevăr, '.self::TARGET_LOCALE.' verificat simetric):');

        foreach ($layers as $layerName => $layer) {
            if (! $this->layerExists($layer)) {
                $this->line("  [{$layerName}] catalog inexistent încă — sărit.");

                continue;
            }

            $pairs = $this->pairsFor($layer);

            if ($pairs === []) {
                $this->line("  [{$layerName}] prezent, dar fără fișiere — nimic de comparat.");

                continue;
            }

            $layerHadDiff = false;

            foreach ($pairs as $relative => $paths) {
                [$sourcePath, $targetPath] = [$paths['source'], $paths['target']];
                $isJson = $layer['parse'] === self::PARSE_JSON;

                // Un fișier prezent doar pe o parte (namespace întreg lipsă pe cealaltă
                // limbă) nu are nevoie de caz special: toate cheile lui ies ca asimetrie.
                $sourceKeys = is_file($sourcePath) ? $this->flattenKeys($this->loadCatalog($sourcePath, $isJson)) : [];
                $targetKeys = is_file($targetPath) ? $this->flattenKeys($this->loadCatalog($targetPath, $isJson)) : [];

                $totalKeysChecked += count($sourceKeys) + count($targetKeys);

                // Doar stratul i18next cunoaște categoriile plurale ale fiecărei limbi.
                // Cele DOUĂ straturi Laravel se compară direct, din același motiv:
                // `trans_choice()` pluralizează prin segmente în interiorul UNEI chei, deci
                // nu există sufixe de categorie. `cldr`, nu „e JSON": stratul
                // `lang/{locale}.json` e tot JSON, dar tot Laravel.
                $expectedSource = $layer['cldr'] ? $this->expectedKeysFor(self::SOURCE_LOCALE, $sourceKeys, $targetKeys) : $targetKeys;
                $expectedTarget = $layer['cldr'] ? $this->expectedKeysFor(self::TARGET_LOCALE, $sourceKeys, $targetKeys) : $sourceKeys;

                $missingInTarget = array_values(array_diff($expectedTarget, $targetKeys));
                $missingInSource = array_values(array_diff($expectedSource, $sourceKeys));
                $orphanInTarget = array_values(array_diff($targetKeys, $expectedTarget));
                $orphanInSource = array_values(array_diff($sourceKeys, $expectedSource));

                if ($missingInTarget === [] && $missingInSource === [] && $orphanInTarget === [] && $orphanInSource === []) {
                    continue;
                }

                $hasFailure = true;
                $layerHadDiff = true;

                $this->newLine();
                $this->line("  [{$layerName}] {$relative}");

                foreach ($missingInTarget as $key) {
                    $this->line('    - lipsă în '.self::TARGET_LOCALE.": {$key}".$this->pluralHint($key, self::TARGET_LOCALE));
                }

                foreach ($missingInSource as $key) {
                    $this->line('    - lipsă în '.self::SOURCE_LOCALE.": {$key}".$this->pluralHint($key, self::SOURCE_LOCALE));
                }

                foreach ($orphanInTarget as $key) {
                    $this->line('    - orfană în '.self::TARGET_LOCALE.' (fără pereche în '.self::SOURCE_LOCALE."): {$key}");
                }

                foreach ($orphanInSource as $key) {
                    $this->line('    - orfană în '.self::SOURCE_LOCALE.' (fără pereche în '.self::TARGET_LOCALE."): {$key}");
                }
            }

            if (! $layerHadDiff) {
                $this->line("  [{$layerName}] OK — ".count($pairs).' fișier(e) verificat(e).');
            }
        }

        if ($hasFailure) {
            $this->newLine();
            $this->components->error('Acoperire chei i18n EȘUATĂ — vezi diferențele de mai sus (ADR-022, FR-I18N-02).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Acoperire chei i18n OK — {$totalKeysChecked} chei verificate pe cele ".count($layers).' straturi.');

        return self::SUCCESS;
    }

    /**
     * Mulțimea de chei pe care catalogul limbii `$locale` TREBUIE să le aibă, dedusă din
     * reuniunea celor două cataloage.
     *
     * O cheie de bază e considerată plurală dacă forma `_other` apare în oricare dintre
     * limbi — `_other` e singura categorie obligatorie în toate limbile, deci prezența ei
     * e semnalul sigur, iar un `foo_one` singuratic (fără `_other`) rămâne tratat ca
     * cheie obișnuită, nu declanșează un fals pozitiv cerând un `foo_other` inventat.
     *
     * @param  list<string>  $sourceKeys
     * @param  list<string>  $targetKeys
     * @return list<string>
     */
    private function expectedKeysFor(string $locale, array $sourceKeys, array $targetKeys): array
    {
        $allKeys = array_unique([...$sourceKeys, ...$targetKeys]);

        $pluralBases = [];
        foreach ($allKeys as $key) {
            if (str_ends_with($key, '_other')) {
                $pluralBases[substr($key, 0, -strlen('_other'))] = true;
            }
        }

        $expected = [];
        foreach ($allKeys as $key) {
            $base = $this->pluralBase($key);

            if ($base !== null && isset($pluralBases[$base])) {
                continue; // tratată mai jos, o singură dată per bază
            }

            $expected[$key] = true;
        }

        foreach (array_keys($pluralBases) as $base) {
            foreach (self::PLURAL_CATEGORIES[$locale] as $category) {
                $expected[$base.'_'.$category] = true;
            }
        }

        $keys = array_keys($expected);
        sort($keys);

        return $keys;
    }

    /**
     * Cheia de bază a unei chei plurale (`rows_many` → `rows`), sau `null` dacă nu poartă
     * niciun sufix de categorie CLDR.
     */
    private function pluralBase(string $key): ?string
    {
        foreach (self::ALL_PLURAL_SUFFIXES as $suffix) {
            if (str_ends_with($key, '_'.$suffix)) {
                return substr($key, 0, -strlen('_'.$suffix));
            }
        }

        return null;
    }

    /**
     * Explicația din dreptul unei chei plurale lipsă — altfel „lipsă în fr: rows_many"
     * arată ca o greșeală de tastare, nu ca o categorie gramaticală obligatorie.
     */
    private function pluralHint(string $key, string $locale): string
    {
        $base = $this->pluralBase($key);

        if ($base === null) {
            return '';
        }

        $category = substr($key, strlen($base) + 1);

        return "  (categoria CLDR „{$category}\" e obligatorie pentru „{$locale}\"; fără ea i18next cade pe fallbackLng, nu pe _other)";
    }

    /**
     * Aplatizează un array (posibil nested) în chei „dot.path" — exact forma folosită
     * de `trans()` și de i18next (`'validation.custom.email.required'`).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }

    /**
     * Există vreun catalog de comparat pentru stratul ăsta? Distins DELIBERAT de „există,
     * dar e gol": un strat absent e sărit grațios (vezi nota de clasă — la Valul 1 niciun
     * catalog nu exista încă), pe când unul prezent și gol merită spus altfel.
     *
     * @param  array<string, mixed>  $layer
     */
    private function layerExists(array $layer): bool
    {
        if ($layer['shape'] === self::SHAPE_LOCALE_FILE) {
            return is_file($this->localeFilePath($layer, self::SOURCE_LOCALE))
                || is_file($this->localeFilePath($layer, self::TARGET_LOCALE));
        }

        return is_dir($layer['root'].'/'.self::SOURCE_LOCALE)
            || is_dir($layer['root'].'/'.self::TARGET_LOCALE);
    }

    /**
     * Perechile de comparat ale unui strat: etichetă → {sursă, țintă}. Normalizează cele
     * DOUĂ forme de catalog într-una singură, ca bucla din `handle()` să nu știe care e
     * care.
     *
     * @param  array<string, mixed>  $layer
     * @return array<string, array{source: string, target: string}>
     */
    private function pairsFor(array $layer): array
    {
        if ($layer['shape'] === self::SHAPE_LOCALE_FILE) {
            $label = self::SOURCE_LOCALE.'.json ↔ '.self::TARGET_LOCALE.'.json';

            return [$label => [
                'source' => $this->localeFilePath($layer, self::SOURCE_LOCALE),
                'target' => $this->localeFilePath($layer, self::TARGET_LOCALE),
            ]];
        }

        $sourceRoot = $layer['root'].'/'.self::SOURCE_LOCALE;
        $targetRoot = $layer['root'].'/'.self::TARGET_LOCALE;

        $relativeFiles = array_unique([
            ...$this->findRelativeFiles($sourceRoot, $layer['pattern']),
            ...$this->findRelativeFiles($targetRoot, $layer['pattern']),
        ]);
        sort($relativeFiles);

        $pairs = [];

        foreach ($relativeFiles as $relative) {
            $pairs[$relative] = [
                'source' => $sourceRoot.'/'.$relative,
                'target' => $targetRoot.'/'.$relative,
            ];
        }

        return $pairs;
    }

    /** @param  array<string, mixed>  $layer */
    private function localeFilePath(array $layer, string $locale): string
    {
        return $layer['root'].'/'.$locale.'.json';
    }

    /**
     * Căile RELATIVE la `$root` ale fișierelor care se potrivesc cu `$pattern`, căutate
     * recursiv — ca `lang/en/emails/order.php` să nu fie ratat. Un `$root` inexistent
     * întoarce listă goală, nu eroare.
     *
     * @return list<string>
     */
    private function findRelativeFiles(string $root, string $pattern): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (! $fileInfo->isFile() || ! fnmatch($pattern, $fileInfo->getFilename())) {
                continue;
            }

            $found[] = substr($fileInfo->getPathname(), strlen($root) + 1);
        }

        sort($found);

        return $found;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function loadCatalog(string $path, bool $json): array
    {
        $value = $json
            ? json_decode((string) file_get_contents($path), true)
            : require $path;

        return is_array($value) ? $value : [];
    }
}
