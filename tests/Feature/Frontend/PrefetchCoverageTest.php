<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * FR-PERF-02 (specs.md §20.1): „Prefetching Inertia 3 pe hover pentru linkurile de
 * navigație principale. [MVP]". Litera cerinței vorbește STRICT despre navigația
 * principală — nu despre orice link din aplicație. Azi există exact două apariții ale
 * propului `prefetch` în tot frontendul: `AppLayout.tsx:155` (linkurile din `<nav
 * aria-label="Primary">`, construite din `NAV_ITEMS`) și `Pages/Settings/Index.tsx:119`
 * (cardurile din hub-ul de Settings, care NU sunt „navigație principală" — sunt legături
 * secundare, dintr-o pagină de listă către sub-ecrane).
 *
 * NICIO discrepanță de raportat: FR-PERF-02 cere prefetch pe navigația principală, iar
 * navigația principală (AppLayout.tsx) îl are deja. Al doilea loc (Settings/Index.tsx) e
 * ÎN PLUS față de literă, nu înlocuiește cerința și nu-i lipsește nimic — nu extinde
 * scopul acestui test (brief-ul pachetului D: „dacă litera cerinței cere mai mult... NU
 * extinde codul de producție" — aici e invers, codul face deja ce cere litera, plus ceva
 * în plus, care nu strică nimic).
 *
 * Sursă verificabilă aleasă, aceeași logică ca `HelpTopicCoverageTest`: parsare a sursei
 * TypeScript a lui `AppLayout.tsx`, izolând întâi blocul `<nav aria-label={t('common:
 * nav.primary')}>…</nav>` (ca să nu se confunde cu alt `<Link>` din același fișier, dacă
 * apare unul mâine), apoi elementul `<Link>` care randează fiecare `NAV_ITEMS` (ancorat pe
 * `{item.label}`, singurul copil al lui — vezi `AppLayout.tsx:151-184`).
 *
 * Verificat că pică la o regresie reală: șters temporar `prefetch` de pe linia 155 din
 * `AppLayout.tsx` → testul a picat cu mesajul de mai jos, apoi codul a fost pus la loc
 * (vezi raportul livrat — codul de producție nu se atinge de acest pachet).
 */
class PrefetchCoverageTest extends TestCase
{
    public function test_primary_navigation_links_carry_prefetch(): void
    {
        $source = file_get_contents(base_path('resources/js/Layouts/AppLayout.tsx'));

        preg_match(
            '/<nav\s+aria-label=\{t\(\'common:nav\.primary\'\)\}[\s\S]*?<\/nav>/',
            $source,
            $navMatch
        );

        $this->assertNotEmpty(
            $navMatch,
            'Blocul <nav aria-label={t(\'common:nav.primary\')}>…</nav> nu s-a putut izola din AppLayout.tsx — regexul a ieșit din sincron cu formatul fișierului sursă.'
        );

        preg_match(
            '/<Link\b[\s\S]*?>\s*\{item\.label\}/',
            $navMatch[0],
            $linkMatch
        );

        $this->assertNotEmpty(
            $linkMatch,
            'Niciun <Link>…{item.label} nu s-a găsit în navigația principală a AppLayout.tsx — regexul a ieșit din sincron cu formatul fișierului sursă (NAV_ITEMS nu se mai randează ca <Link>, sau structura JSX s-a schimbat).'
        );

        $this->assertMatchesRegularExpression(
            '/\bprefetch\b/',
            $linkMatch[0],
            'FR-PERF-02: linkurile din navigația principală (AppLayout.tsx) nu mai poartă propul `prefetch`.'
        );
    }
}
