<?php

namespace Tests\Feature\Frontend;

use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * GDPR-06 (audit 2026-09-23, §3, Art. 13/14) — Politica de confidențialitate și Termenii de
 * utilizare sunt o suprafață publică minimă, cu nume de rută FIXE (`legal.privacy`/
 * `legal.terms`, `routes/web.php`) pentru ca alte instrumente (scanarea de accesibilitate)
 * să le găsească fără să depindă de o cale literală. Ambele stau deliberat în AFARA
 * grupurilor `guest` și `auth` + `workspace` — testele de mai jos verifică exact asta: 200
 * anonim ȘI 200 autentificat, cu componenta Inertia corectă.
 *
 * Linkurile către ele (Login, footerul ambelor layout-uri) sunt verificate static, pe sursa
 * TypeScript — același tipar ca `DemoBannerCoverageTest`/`EmptyStateCoverageTest`: Pest
 * rulează în PHP, fără randare de browser în această suită.
 */
class LegalPagesTest extends TestCase
{
    public function test_privacy_page_renders_for_a_guest(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Privacy'));
    }

    public function test_terms_page_renders_for_a_guest(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Terms'));
    }

    public function test_privacy_page_renders_for_an_authenticated_member(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $user = $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        $this->actingAs($user)
            ->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Privacy'));
    }

    public function test_terms_page_renders_for_an_authenticated_member(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $user = $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        $this->actingAs($user)
            ->get('/terms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Terms'));
    }

    public function test_the_route_names_and_paths_are_the_fixed_ones_expected_by_other_tooling(): void
    {
        $this->assertSame('/privacy', route('legal.privacy', absolute: false));
        $this->assertSame('/terms', route('legal.terms', absolute: false));
    }

    public function test_the_login_page_links_to_both_legal_pages(): void
    {
        $source = file_get_contents(base_path('resources/js/Pages/Auth/Login.tsx'));

        $this->assertStringContainsString('href="/privacy"', $source, 'Login.tsx nu leagă către /privacy.');
        $this->assertStringContainsString('href="/terms"', $source, 'Login.tsx nu leagă către /terms.');
    }

    /**
     * Enumerare DINAMICĂ (`glob`), ca în `DemoBannerCoverageTest`: dacă mâine apare un
     * `Layouts/AdminLayout.tsx` nou fără footer legal, testul trebuie să pice automat.
     */
    public function test_every_layout_links_to_the_legal_pages_from_a_footer_element(): void
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

            $this->assertMatchesRegularExpression(
                '/<footer\b[\s\S]*<\/footer>/',
                $source,
                "GDPR-06: {$label} nu are un element <footer> — linkurile legale trebuie să stea acolo, nu în <main>."
            );

            preg_match('/<footer\b[\s\S]*<\/footer>/', $source, $match);
            $footer = $match[0];

            $this->assertStringContainsString('href="/privacy"', $footer, "GDPR-06: footer-ul din {$label} nu leagă către /privacy.");
            $this->assertStringContainsString('href="/terms"', $footer, "GDPR-06: footer-ul din {$label} nu leagă către /terms.");

            $checked[] = $label;
        }

        $this->assertContains('AppLayout.tsx', $checked, 'AppLayout.tsx trebuie să fie mereu verificabil — dacă nu e, testul nu verifică nimic.');
        $this->assertContains('GuestLayout.tsx', $checked, 'GuestLayout.tsx trebuie să fie mereu verificabil — dacă nu e, testul nu verifică nimic.');
    }
}
