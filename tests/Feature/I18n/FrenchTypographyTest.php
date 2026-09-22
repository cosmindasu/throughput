<?php

namespace Tests\Feature\I18n;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Garda tipografică franceză, ADR-022 / FR-I18N-04 („mesaje de validare" — dar regula de
 * mai jos privește TOT textul francez vizibil, nu doar validarea) — impune, pe TOATE
 * cataloagele `fr`, singura convenție corectă tipografic: **U+202F (NARROW NO-BREAK SPACE)**
 * înaintea lui „?", „!", „;", „:" și „»", și imediat după „«". Un spațiu normal (U+0020) sau
 * un U+00A0 (NBSP obișnuit, o greșeală ușor de făcut fiindcă e vizual identic) în locul lui
 * NU trece.
 *
 * Context măsurat, nu presupus: la Valul 3 al Lotului I18N proiectul a ajuns cu 735 de
 * ocurențe deja corecte pe U+202F și 84 de abateri amestecate (spațiu normal, plus UN
 * U+00A0 în `resources/js/locales/fr/orders.json`). Valul 4 a fost consecvent de la
 * început. Valul 5 a renormalizat cele 84 și a adăugat testul ăsta EXACT ca abaterile să nu
 * se strecoare înapoi — un catalog crește mereu, iar cineva care adaugă o cheie nouă scrie,
 * implicit, spațiu ASCII simplu, fiindcă e caracterul de pe tastatură.
 *
 * Verifică AMBELE straturi pe care trăiește text francez vizibil de utilizator, la fel ca
 * `App\Console\Commands\I18nCoverage` (aceeași tehnică de parcurgere recursivă):
 *   - `lang/fr/*.php`, incluse cu `require`, nu citite prin regex — un parser PHP real vede
 *     exact array-ul pe care îl vede și Laravel, indiferent de formatare;
 *   - `resources/js/locales/fr/*.json`, decodate și aplatizate recursiv — INCLUSIV array-urile
 *     (`help.json` are liste de fraze sub `whatCanYouDo`/`rules`, nu doar obiecte).
 *
 * **Capcana centrală, deja plătită o dată în timpul auditului manual al Valului 5**: o
 * potrivire naivă pe „:" produce sute de false pozitive, fiindcă `:count`, `:status`,
 * `:attribute`… sunt SUBSTITUENȚI Laravel, nu punctuație — la fel `{{count}}` (interpolare
 * i18next), `$t(roles:manager)` (referință cross-namespace i18next), un URL (`https://…`) sau
 * un span de cod între backtick-uri. `maskNonProse()` de mai jos le exclude pe toate ÎNAINTE
 * de a căuta punctuație — exact logica folosită ca să numere cele 84 de abateri (păstrată
 * aici ca gardă permanentă, nu doar ca script de unică folosință).
 *
 * De asemenea exclus: un „:" între DOUĂ cifre (`14:00`) — oră, nu punctuație dublă.
 *
 * **`lang/fr/validation.php` e un compromis asumat, nu ascuns.** Fișierul e PUBLICAT din
 * framework (`3af6807`) și scris de mână prin comparație cu englezul VERBATIM din
 * `lang/en/validation.php` — vezi docblock-ul lui pentru detalii. Testul de mai jos ÎL scanează
 * ca pe oricare alt fișier (o singură convenție e chiar cerința acestui val), dar
 * `vendor/laravel/framework` nu are variantă franceză de comparat, iar procedura de upgrade
 * documentată acolo („se adaugă cheile noi în AMBELE limbi") nu impune nicăieri U+202F — deci
 * o cheie nouă tastată manual, de cineva care nu cunoaște regula asta, va reintroduce spațiul
 * ASCII simplu. Dacă testul ăsta pică EXACT pe `validation.php` după un upgrade de Laravel, e
 * acest scenariu, nu un bug al gărzii — de renormalizat, nu de investigat ca regresie nouă.
 *
 * Mesajul de eșec dă fișierul, cheia („dot.path", cu index numeric pentru array-uri) și
 * ±40 de caractere de context — nu doar „a picat": cine sparge garda peste șase luni trebuie
 * să poată repara din mesaj, fără să reruleze un script separat.
 */
class FrenchTypographyTest extends TestCase
{
    /** U+202F — NARROW NO-BREAK SPACE, singurul spațiu corect aici. */
    private const NNBSP = "\u{202F}";

    /** U+00A0 — NBSP obișnuit, vizual identic cu U+202F dar greșit aici. */
    private const NBSP = "\u{00A0}";

    /** Semnele care cer U+202F ÎNAINTE de ele. „»" e guillemet-ul închizător. */
    private const NEEDS_SPACE_BEFORE = ['?', '!', ';', ':', '»'];

    public function test_lang_php_catalogs_use_narrow_no_break_space_before_double_punctuation(): void
    {
        $violations = [];

        foreach ($this->findFiles(base_path('lang/fr'), '*.php') as $path) {
            $strings = $this->flattenStrings((array) require $path);

            array_push($violations, ...$this->violationsIn($path, $strings));
        }

        $this->assertSame([], $violations, $this->describe($violations));
    }

    public function test_i18next_json_catalogs_use_narrow_no_break_space_before_double_punctuation(): void
    {
        $violations = [];

        foreach ($this->findFiles(resource_path('js/locales/fr'), '*.json') as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $strings = $this->flattenStrings(is_array($decoded) ? $decoded : []);

            array_push($violations, ...$this->violationsIn($path, $strings));
        }

        $this->assertSame([], $violations, $this->describe($violations));
    }

    /**
     * @param  array<string, string>  $strings  „dot.path" => text
     * @return list<string> o linie de eroare gata de citit, per abatere
     */
    private function violationsIn(string $path, array $strings): array
    {
        $lines = [];

        foreach ($strings as $key => $text) {
            foreach ($this->typographyViolations($text) as $violation) {
                $lines[] = sprintf(
                    '%s [%s] — %s : "…%s…"',
                    $path,
                    $key,
                    $violation['kind'],
                    $violation['context'],
                );
            }
        }

        return $lines;
    }

    /** @param  list<string>  $violations */
    private function describe(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        return "Spațiu incorect lângă punctuație dublă franceză (se așteaptă U+202F):\n"
            .implode("\n", $violations);
    }

    /**
     * Mută cursorul din `maskNonProse()` pe fiecare caracter și raportează abaterile.
     * Oglindește 1:1 logica folosită la numărarea manuală a celor 84 de abateri ale Valului 5.
     *
     * @return list<array{kind: string, context: string}>
     */
    private function typographyViolations(string $text): array
    {
        $masked = $this->maskNonProse($text);
        $length = count($masked);
        $violations = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $masked[$i];

            if ($char === null) {
                continue;
            }

            if (in_array($char, self::NEEDS_SPACE_BEFORE, true)) {
                $prev = $i > 0 ? $masked[$i - 1] : null;

                // Nimic înainte, sau un substituent/URL/span de cod mascat: nu e o abatere,
                // fiindcă nu există niciun spațiu de verificat.
                if ($prev === null || $prev === self::NNBSP) {
                    continue;
                }

                // „14:00" — „:" între două cifre e o oră, nu punctuație dublă.
                if ($char === ':' && preg_match('/[0-9]/', $prev) === 1) {
                    $next = $masked[$i + 1] ?? null;
                    if ($next !== null && preg_match('/[0-9]/', $next) === 1) {
                        continue;
                    }
                }

                if ($prev === ' ' || $prev === self::NBSP) {
                    $violations[] = [
                        'kind' => $prev === self::NBSP
                            ? 'U+00A0 în loc de U+202F înaintea „'.$char.'"'
                            : 'spațiu normal în loc de U+202F înaintea „'.$char.'"',
                        'context' => $this->context($text, $i - 40, $i + 3),
                    ];
                }
            }

            if ($char === '«') {
                $next = $i + 1 < $length ? $masked[$i + 1] : null;

                if ($next === null || $next === self::NNBSP) {
                    continue;
                }

                if ($next === ' ' || $next === self::NBSP) {
                    $violations[] = [
                        'kind' => $next === self::NBSP
                            ? 'U+00A0 în loc de U+202F după „«"'
                            : 'spațiu normal în loc de U+202F după „«"',
                        'context' => $this->context($text, $i, $i + 41),
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Exclude din analiză ce NU e proză franceză, ÎNAINTE de a căuta punctuație: span-uri de
     * cod între backtick-uri, URL-uri, interpolări i18next (`{{count}}`), referințe
     * cross-namespace i18next (`$t(roles:manager)`) și substituenți Laravel (`:count`,
     * `:attribute`…). Fără excluderile astea, orice `:xxx` ar fi confundat cu punctuație.
     *
     * @return list<?string> un „caracter" Unicode per poziție; `null` = mascat
     */
    private function maskNonProse(string $text): array
    {
        $chars = mb_str_split($text, 1, 'UTF-8');

        $mask = function (string $pattern) use (&$chars, $text): void {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                return;
            }

            foreach ($matches[0] as [$matched, $byteOffset]) {
                // `PREG_OFFSET_CAPTURE` întoarce un offset în OCTEȚI, chiar cu modificatorul
                // `u` — conversia la index de caracter e obligatorie pe text UTF-8.
                $start = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
                $length = mb_strlen($matched, 'UTF-8');

                for ($i = $start; $i < $start + $length; $i++) {
                    $chars[$i] = null;
                }
            }
        };

        $mask('/`[^`]*`/');
        $mask('#https?://\S+#');
        $mask('/\{\{[^}]*}}/');
        $mask('/\$t\([^)]*\)/');
        $mask('/:[A-Za-z_][A-Za-z0-9_]*/');

        return $chars;
    }

    private function context(string $text, int $from, int $to): string
    {
        $from = max(0, $from);

        return mb_substr($text, $from, max(0, $to - $from), 'UTF-8');
    }

    /**
     * Aplatizează un catalog (posibil nested, cu array-uri indexate — `help.json` are liste
     * de fraze) în chei „dot.path", ca `App\Console\Commands\I18nCoverage::flattenKeys()`.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string> „dot.path" (index numeric pentru liste) => text
     */
    private function flattenStrings(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $this->flattenStrings($value, $path);

                continue;
            }

            if (is_string($value)) {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * Parcurgere RECURSIVĂ, ca `App\Console\Commands\I18nCoverage::findRelativeFiles()` — un
     * viitor subdirector (`lang/fr/emails/order.php`, `resources/js/locales/fr/help/…json`)
     * nu trebuie să scape gărzii.
     *
     * @return list<string> căi absolute, sortate
     */
    private function findFiles(string $root, string $pattern): array
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
            if ($fileInfo->isFile() && fnmatch($pattern, $fileInfo->getFilename())) {
                $found[] = $fileInfo->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
