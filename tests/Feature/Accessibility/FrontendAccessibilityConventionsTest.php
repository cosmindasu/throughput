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
     * inclusiv în interiorul unui `<dialog>` modal. Forma corectă e `pending` pe `Button`
     * (`aria-disabled` + clic neutralizat + etichetă „…"), plus un retur timpuriu în handler.
     *
     * Regula vizează DOAR stările „cererea mea e în zbor" — `disabled` rămâne legitim pe un
     * control care nu poate avea focus în momentul în care se blochează (ex. un buton care
     * depinde de un `<select>` pe care utilizatorul îl operează chiar atunci).
     */
    public function test_no_button_is_natively_disabled_while_its_own_request_is_in_flight(): void
    {
        $offenders = [];

        foreach ($this->frontendSources() as $path) {
            // `(?<![\w-])` exclude `aria-disabled={processing}` — chiar FORMA CORECTĂ, care
            // altfel ar fi raportată ca încălcare (`\b` se potrivește și după cratimă).
            if (preg_match_all('/(?<![\w-])disabled=\{[^}]*\b(processing|loading|submitting|saving|sending)/i', file_get_contents($path), $matches)) {
                $offenders[] = $this->relative($path).' ('.implode(', ', $matches[0]).')';
            }
        }

        $this->assertSame([], $offenders, '`disabled` nativ pe un buton blocat de propria cerere — folosește `<Button pending={…}>`: '.implode('; ', $offenders));
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
}
