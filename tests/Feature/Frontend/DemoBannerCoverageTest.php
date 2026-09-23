<?php

namespace Tests\Feature\Frontend;

use Tests\TestCase;

/**
 * FR-PUB-03 — bannerul de demo apare pe ORICE pagină, autentificată sau nu (specs.md,
 * „suprafață publică minimă"). Azi randat manual în `resources/js/Layouts/AppLayout.tsx`
 * și `resources/js/Layouts/GuestLayout.tsx`, fără niciun test — pachetul D cere exact
 * acoperirea care lipsește.
 *
 * Sursă verificabilă aleasă și DE CE, cu aceeași logică ca precedentul exact al acestui
 * tipar în repo — `tests/Feature/Help/HelpTopicCoverageTest.php`:
 *
 *  1. Parsare a sursei TypeScript, nu o randare React — Pest rulează în PHP; proiectul nu
 *     are niciun test runner JS instalat (Vitest/RTL), iar unul singur, doar pentru trei
 *     teste de acoperire, ar fi mult cost pentru puțin câștig (`.ai/rules/frontend.md` nu
 *     menționează așa ceva). Regexul/`str_contains` citește exact ce există în fișier.
 *  2. Enumerarea directorului e DINAMICĂ (`glob`), nu o listă `AppLayout.tsx`/
 *     `GuestLayout.tsx` hardcodată în test: fiecare `.tsx` din `resources/js/Layouts/` e un
 *     shell montat direct de o pagină Inertia (`Component.layout = (page) => <XLayout>{page}
 *     </XLayout>`) — dacă mâine apare un `Layouts/AdminLayout.tsx` nou fără `<DemoBanner
 *     />`, testul trebuie să pice automat, fără nicio modificare aici. Asta e toată
 *     valoarea lui, cerută explicit în brief-ul pachetului D.
 *
 * Verificat că pică la o regresie reală: comentat temporar `<DemoBanner />` din
 * `GuestLayout.tsx` → testul a picat cu mesajul de mai jos, apoi codul a fost pus la loc
 * (vezi raportul livrat, nu acest fișier — codul de producție nu se atinge de acest pachet).
 */
class DemoBannerCoverageTest extends TestCase
{
    public function test_every_layout_renders_the_demo_banner(): void
    {
        $layoutFiles = glob(base_path('resources/js/Layouts/*.tsx'));

        $this->assertNotEmpty(
            $layoutFiles,
            'Niciun fișier .tsx găsit în resources/js/Layouts/ — globul a ieșit din sincron cu structura de directoare.'
        );

        $checked = [];

        foreach ($layoutFiles as $file) {
            $source = file_get_contents($file);
            $label = basename($file);

            $this->assertStringContainsString(
                '<DemoBanner',
                $source,
                sprintf(
                    'FR-PUB-03: %s nu randează <DemoBanner /> — bannerul de demo trebuie să apară pe orice pagină, autentificată sau nu.',
                    $label
                )
            );

            $checked[] = $label;
        }

        // Gardă minimă, ca în `HelpTopicCoverageTest::test_every_built_navigation_route…`:
        // cele două layout-uri cunoscute azi trebuie să fie mereu prezente în enumerare —
        // dacă `glob` ar întoarce vreodată o listă goală sau parțială fără să pice mai sus
        // (ex. cale greșită), testul n-ar verifica nimic și tot ar trece „verde".
        $this->assertContains(
            'AppLayout.tsx',
            $checked,
            'AppLayout.tsx trebuie să fie mereu verificabil — dacă nu e, testul de acoperire nu verifică nimic.'
        );
        $this->assertContains(
            'GuestLayout.tsx',
            $checked,
            'GuestLayout.tsx trebuie să fie mereu verificabil — dacă nu e, testul de acoperire nu verifică nimic.'
        );
    }
}
