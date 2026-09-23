<?php

namespace Tests\Feature\Accessibility;

use Tests\TestCase;

/**
 * Gărzi de regresie pentru convențiile de accesibilitate care se strică TĂCUT.
 *
 * De ce static, pe sursă, și nu un test de browser: axe-core rulează în suita Playwright
 * (`e2e/`), care are nevoie de server + browser și e scumpă; regulile de mai jos sunt însă
 * invariante de COD, verificabile fără să randezi nimic, și tocmai ele se pierd la primul
 * ecran nou scris după tipar vechi. Precedent în suită: `InertiaPagePathsTest`, care
 * verifică tot o convenție de fișiere, nu comportament.
 *
 * Niciuna dintre ele nu înlocuiește auditul manual sau scanarea axe — acoperă exact cele
 * trei tipare pe care auditul Fazei 5 le-a găsit repetate pe ecranele Fazelor 2-4.
 */
class FrontendAccessibilityConventionsTest extends TestCase
{
    /**
     * SC 2.4.2 (Page Titled). Într-un SPA Inertia, o pagină fără `<Head title>` NU e o
     * pagină „fără titlu": moștenește titlul documentului al paginii ANTERIOARE, deci cine
     * navighează cu un cititor de ecran aude un titlu GREȘIT, nu unul lipsă — modul de eșec
     * cel mai greu de observat vizual. Găsit pe `Unassigned/Index` și `Settings/Members/Index`.
     */
    public function test_every_inertia_page_component_sets_a_document_title(): void
    {
        $missing = [];

        foreach ($this->pageComponents() as $path) {
            if (! str_contains(file_get_contents($path), '<Head')) {
                $missing[] = $this->relative($path);
            }
        }

        $this->assertSame([], $missing, 'Pagini Inertia fără `<Head title>` (SC 2.4.2 — titlul rămâne al paginii anterioare): '.implode(', ', $missing));
    }

    /**
     * Convenția de nume de tabel din `.ai/rules/frontend.md`: implicit `<caption class="sr-only">`
     * (tehnica H39), sau `aria-labelledby` când tabelul are un heading vizibil DEDICAT chiar
     * deasupra. Auditul a găsit trei convenții simultan, a treia fiind „niciuna".
     *
     * Nu e, în sine, o încălcare AA (SC 1.3.1/4.1.2 privesc relația `<th scope>`/celulă) — e
     * calitate de orientare, la cost aproape zero, pe care testul o ține uniformă.
     */
    public function test_every_table_carries_a_name(): void
    {
        $unnamed = [];

        foreach ($this->frontendSources() as $path) {
            $source = file_get_contents($path);

            foreach ($this->openingTagsOf('table', $source) as $offset => $tag) {
                $hasLabelledBy = str_contains($tag, 'aria-labelledby');
                // `<caption>` e primul copil al tabelului; fereastra acoperă tagul de
                // deschidere plus eventualul comentariu explicativ dinaintea lui.
                $hasCaption = str_contains(substr($source, $offset, 900), '<caption');

                if (! $hasLabelledBy && ! $hasCaption) {
                    $unnamed[] = $this->relative($path);
                }
            }
        }

        $this->assertSame([], array_unique($unnamed), 'Tabele fără nume (nici `<caption>`, nici `aria-labelledby`): '.implode(', ', array_unique($unnamed)));
    }

    /**
     * Capcana măsurată din `.ai/rules/frontend.md`: `disabled` nativ pe butonul care ARE
     * focusul (exact cel tocmai apăsat) îl blurează, iar browserul mută focusul pe `<body>` —
     * inclusiv în interiorul unui `<dialog>` modal. Forma corectă e `aria-disabled` + un
     * handler no-op/`pending` pe `Button`, plus un retur timpuriu în handler.
     *
     * Lărgit la audit (A11Y-05/FE-02): prima versiune verifica DOAR variabile numite
     * `processing|loading|submitting|saving|sending`, deci rata `disabled={isFirst}` din
     * `Pipeline/Index.tsx` (Move up/down la capătul listei — exact aceeași capcană, altă
     * cauză decât „cererea mea e în zbor"). Acum verificăm ORICE `disabled={...}` aflat
     * într-un tag de deschidere `<button>`/`<Button>`, minus lista albă motivată de mai jos
     * (`nativeDisableWhitelist()`, pe modelul `modelsNeverExposedThroughAUrl()` din
     * `ArchitectureTest`).
     *
     * Scoping-ul pe `<button>`/`<Button>` exclude STRUCTURAL `<input disabled>`/
     * `<fieldset disabled>` — capcana asta (blurarea sub propriul click) nu li se aplică:
     * niciunul din cele două nu e ținta unui click care-l blochează pe el însuși.
     */
    public function test_no_button_is_natively_disabled_without_a_documented_reason(): void
    {
        $offenders = [];

        foreach ($this->frontendSources() as $path) {
            $relative = $this->relative($path);
            $source = file_get_contents($path);

            foreach (['button', 'Button'] as $element) {
                foreach ($this->jsxOpeningTagsOf($element, $source) as $tag) {
                    // `(?<![\w-])` exclude `aria-disabled={...}` — forma CORECTĂ, care altfel
                    // ar fi raportată ca încălcare (`\b` se potrivește și după cratimă).
                    if (! preg_match('/(?<![\w-])disabled=\{[^}]*\}/', $tag, $match)) {
                        continue;
                    }

                    $expression = trim($match[0]);

                    if ($this->isWhitelistedNativeDisable($relative, $expression)) {
                        continue;
                    }

                    $offenders[] = "{$relative} ({$expression})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '`disabled` nativ pe un `<button>`/`<Button>` fără o excepție motivată — folosește `aria-disabled` + handler no-op, sau adaugă o intrare motivată în `nativeDisableWhitelist()`: '.implode('; ', $offenders),
        );
    }

    /**
     * Listă ALBĂ, motivată individual — fiecare intrare verificată prin `Read` direct pe
     * fișierul sursă (2026-09-23), nu presupusă. Toate sunt controale a căror stare
     * `disabled` depinde de UN ALT câmp (`<select>`/`<input>`) pe care utilizatorul îl
     * operează ÎN ACEL MOMENT — focusul e pe câmpul respectiv, nu pe buton, deci comutarea
     * lui `disabled` nu-l poate blura pe ACESTA din urmă. Capcana reală (descrisă mai sus) e
     * „butonul tocmai apăsat devine `disabled` sub propriul lui click" — asta e altceva.
     *
     * Cheia e calea relativă a fișierului; valoarea e fragmentul EXACT `disabled={...}`
     * găsit. Dacă fragmentul se schimbă, whitelistul nu se mai potrivește și testul
     * redevine roșu, intenționat — cere o nouă verificare, nu doar actualizarea listei.
     *
     * - `Components/BulkSelectionBar.tsx` (5×, în afara feliei acestui agent) —
     *   `disabled={!ownerId || overRowCap}` / `disabled={!validAmount || overRowCap}` /
     *   `disabled={overRowCap}` (×3): fiecare depinde de un `<select>`/`<input>` din
     *   ACELAȘI dialog (owner de reasignat / sumă de ajustat) sau de `overRowCap` (prag
     *   static, calculat din selecția de rânduri — nu din propriul click al butonului).
     *   Fiecare are deja `aria-disabled={processing || undefined}` separat pentru starea
     *   „cerere în zbor".
     * - `Components/GlobalSearch.tsx` (în afara feliei) — `disabled={!workspace}`:
     *   workspace-ul e context de pagină, stabilit înainte ca butonul să existe pe ecran —
     *   nu se schimbă printr-un click pe ACEST buton.
     * - `Components/Members/DeactivateMemberDialog.tsx`, `Pages/Unassigned/Index.tsx`
     *   (ambele în afara feliei) — `disabled={newOwnerUserId === ''}`: depinde de un
     *   `<select>` de reasignare aflat în ACELAȘI dialog/formular.
     *
     * @return array<string, list<string>>
     */
    private function nativeDisableWhitelist(): array
    {
        return [
            'resources/js/Components/BulkSelectionBar.tsx' => [
                'disabled={!ownerId || overRowCap}',
                'disabled={overRowCap}',
                'disabled={!validAmount || overRowCap}',
            ],
            'resources/js/Components/GlobalSearch.tsx' => [
                'disabled={!workspace}',
            ],
            'resources/js/Components/Members/DeactivateMemberDialog.tsx' => [
                "disabled={newOwnerUserId === ''}",
            ],
            'resources/js/Pages/Unassigned/Index.tsx' => [
                "disabled={newOwnerUserId === ''}",
            ],
        ];
    }

    private function isWhitelistedNativeDisable(string $relativePath, string $expression): bool
    {
        $whitelist = $this->nativeDisableWhitelist();

        return isset($whitelist[$relativePath]) && in_array($expression, $whitelist[$relativePath], true);
    }

    /**
     * SC 3.1.1 (Language of Page) + SC 1.4.4 (Resize Text): limba e declarată pe `<html>`, iar
     * viewport-ul nu blochează zoom-ul. Ambele trăiesc în shell-ul Blade, deci se pot verifica
     * pe un răspuns real, fără browser.
     */
    public function test_the_application_shell_declares_a_language_and_allows_zoom(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<html[^>]+lang="[a-z]{2}/i', $html, 'SC 3.1.1 — `<html>` fără atribut `lang`.');
        $this->assertStringNotContainsString('user-scalable=no', $html, 'SC 1.4.4 — viewport-ul blochează zoom-ul.');
        $this->assertStringNotContainsString('maximum-scale', $html, 'SC 1.4.4 — viewport-ul plafonează zoom-ul.');
    }

    /** @return list<string> */
    private function pageComponents(): array
    {
        return $this->tsxFilesIn(resource_path('js/Pages'));
    }

    /** @return list<string> */
    private function frontendSources(): array
    {
        return [...$this->tsxFilesIn(resource_path('js/Pages')), ...$this->tsxFilesIn(resource_path('js/Components'))];
    }

    /** @return list<string> */
    private function tsxFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'tsx') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Tagurile de deschidere ale unui element, indexate după poziția lor în sursă.
     *
     * @return array<int, string>
     */
    private function openingTagsOf(string $element, string $source): array
    {
        preg_match_all('/<'.preg_quote($element, '/').'\b[^>]*>/', $source, $matches, PREG_OFFSET_CAPTURE);

        $tags = [];

        foreach ($matches[0] as [$tag, $offset]) {
            $tags[$offset] = $tag;
        }

        return $tags;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    /**
     * Ca `openingTagsOf()`, dar respectă acolade `{...}` IMBRICATE în interiorul tagului —
     * necesar pe `<button>`/`<Button>`, unde un handler ca `onClick={() => setX(...)}`
     * conține o săgeată `=>`, adică un `>` literal ÎNAINTE de sfârșitul real al tagului.
     * `openingTagsOf()` (regex simplu `[^>]*>`) s-ar opri acolo, trunchiind tagul înainte
     * de atributele următoare — posibil chiar `disabled={...}` — și ar rata cazul, nu l-ar
     * raporta greșit. De-aia testul de mai sus NU refolosește `openingTagsOf()`.
     *
     * Nu recunoaște un `>` literal în interiorul unui șir simplu, necotat prin `{}` (ex.
     * `className="[&>svg]:..."`) — verificat prin grep (2026-09-23): nicio apariție în
     * `resources/js`, deci simplificarea e sigură azi, nu doar presupusă.
     *
     * @return list<string>
     */
    private function jsxOpeningTagsOf(string $element, string $source): array
    {
        $tags = [];
        $needle = '<'.$element;
        $offset = 0;
        $length = strlen($source);

        while (($start = strpos($source, $needle, $offset)) !== false) {
            $afterName = $start + strlen($needle);
            $nextChar = $afterName < $length ? $source[$afterName] : '';

            // Graniță de „cuvânt" după nume — `<Button` nu trebuie să prindă `<ButtonLink`.
            if ($nextChar !== '' && (ctype_alnum($nextChar) || $nextChar === '_' || $nextChar === '-')) {
                $offset = $afterName;

                continue;
            }

            $depth = 0;
            $pos = $afterName;
            $tagEnd = null;

            while ($pos < $length) {
                $char = $source[$pos];

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                } elseif ($char === '>' && $depth <= 0) {
                    $tagEnd = $pos;
                    break;
                }

                $pos++;
            }

            if ($tagEnd === null) {
                break;
            }

            $tags[] = substr($source, $start, $tagEnd - $start + 1);
            $offset = $tagEnd + 1;
        }

        return $tags;
    }
}
