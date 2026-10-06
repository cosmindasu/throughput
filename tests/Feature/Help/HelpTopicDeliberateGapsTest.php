<?php

namespace Tests\Feature\Help;

use Tests\TestCase;

/**
 * `HelpTopicCoverageTest` parcurge `NAV_ITEMS` plus Dashboard — adică ecranele cu link în bara
 * principală. Restul proiectului nu era acoperit de nimic: la 2026-10-06 existau 59 de
 * componente de pagină și 50 de mapări în `resources/js/help/index.ts`, deci NOUĂ ecrane fără
 * subiect, fără ca ceva să spună dacă e intenție sau scăpare. (Planul spunea „50 de componente
 * Inertia", confundând componentele MAPATE cu toate componentele.)
 *
 * Testul ăsta nu cere subiect pentru fiecare pagină — ar fi greșit. Cere ca fiecare pagină să
 * aibă ori un subiect, ori un rând în `WITHOUT_TOPIC` cu motivul lui. Diferența practică: a
 * zecea pagină fără subiect pică testul, iar cine o adaugă e obligat să decidă, nu să uite.
 *
 * Motivul dominant nu e editorial, ci structural: `HelpPanel` e montat DOAR în `AppLayout`
 * (`resources/js/Layouts/AppLayout.tsx`). O pagină pe `GuestLayout` n-are de unde deschide
 * panoul, deci un subiect scris pentru ea ar fi text pe care nimeni nu-l poate citi — motiv
 * verificat de `test_pages_exempted_for_living_outside_the_app_shell_really_do()`, nu doar
 * declarat în comentariu.
 */
class HelpTopicDeliberateGapsTest extends TestCase
{
    /**
     * Ecrane fără subiect de ajutor, DELIBERAT. Cheia e componenta Inertia; valoarea e motivul.
     *
     * @var array<string, string>
     */
    private const WITHOUT_TOPIC = [
        'Auth/Login' => 'guest',
        'Auth/ForgotPassword' => 'guest',
        'Auth/ResetPassword' => 'guest',
        'Invitations/Accept' => 'guest',
        'Legal/Privacy' => 'guest',
        'Legal/Terms' => 'guest',
        'Settings/Billing/AccessBlocked' => 'guest',
        // Pe `AppLayout`, deci panoul S-AR putea deschide — dar n-are ce explica. Landing-ul
        // public (FR-PUB-01) ESTE explicația produsului; un manual peste el ar fi un manual
        // despre o pagină de prezentare.
        'Welcome' => 'landing public — pagina e ea însăși explicația',
        // La fel, pe `AppLayout`: pagina de eroare in-app (ADR-024). Cine o vede caută ieșirea,
        // nu regulile de business ale ecranului pe care n-a ajuns.
        'Error' => 'pagină de eroare — cititorul caută ieșirea, nu manualul',
    ];

    /** Paginile care nu pot deschide panoul fiindcă nu trec prin `AppLayout`. */
    private const GUEST_REASON = 'guest';

    public function test_every_inertia_page_has_a_help_topic_or_a_recorded_reason_for_not_having_one(): void
    {
        $missing = array_values(array_diff($this->pageComponents(), $this->mappedComponents(), array_keys(self::WITHOUT_TOPIC)));

        $this->assertSame([], $missing, 'Pagini fără subiect de ajutor ȘI fără motiv consemnat: '
            .implode(', ', $missing).". Adaugă un subiect în resources/js/help/index.ts, sau un rând în WITHOUT_TOPIC cu motivul.");
    }

    public function test_the_exemption_list_does_not_rot(): void
    {
        $pages = $this->pageComponents();
        $mapped = $this->mappedComponents();

        foreach (array_keys(self::WITHOUT_TOPIC) as $component) {
            $this->assertContains($component, $pages, "WITHOUT_TOPIC conține „{$component}”, care nu mai există pe disc.");
            $this->assertNotContains($component, $mapped, "„{$component}” are acum subiect de ajutor — scoate-l din WITHOUT_TOPIC.");
        }
    }

    /**
     * Controlul pe MOTIV, nu doar pe listă: dacă una dintre paginile scutite „fiindcă e în
     * afara shell-ului” ajunge pe `AppLayout`, scutirea devine falsă și trebuie recitită.
     */
    public function test_pages_exempted_for_living_outside_the_app_shell_really_do(): void
    {
        $this->assertStringContainsString(
            'HelpPanel',
            file_get_contents(base_path('resources/js/Layouts/AppLayout.tsx')),
            'Premisa întregii scutiri: panoul de ajutor se montează în AppLayout.'
        );

        foreach (self::WITHOUT_TOPIC as $component => $reason) {
            if ($reason !== self::GUEST_REASON) {
                continue;
            }

            $source = file_get_contents(base_path("resources/js/Pages/{$component}.tsx"));

            $this->assertMatchesRegularExpression(
                '/\.layout\s*=.*GuestLayout/s',
                $source,
                "„{$component}” e scutit fiindcă nu trece prin AppLayout, dar nu mai folosește GuestLayout — motivul scutirii nu mai e adevărat."
            );
        }
    }

    /** @return list<string> */
    private function pageComponents(): array
    {
        $base = base_path('resources/js/Pages');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $components = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'tsx') {
                $components[] = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1, -4));
            }
        }

        sort($components);

        return $components;
    }

    /**
     * Mapările din `resources/js/help/index.ts`. Citite prin regex, din acelaşi motiv ca în
     * `HelpTopicCoverageTest`: Pest rulează în PHP și nu poate importa un modul `.ts`.
     *
     * @return list<string>
     */
    private function mappedComponents(): array
    {
        preg_match_all(
            "/^\s+'([A-Za-z\/]+)':\s*[A-Za-z]+,$/m",
            file_get_contents(base_path('resources/js/help/index.ts')),
            $matches
        );

        $mapped = array_values(array_unique($matches[1]));
        sort($mapped);

        return $mapped;
    }
}
