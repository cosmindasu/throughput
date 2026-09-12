<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Scheletul de aplicație din Sprint 0: props comune, tema fără licărire, preload de fonturi.
 *
 * Testele de mai jos nu verifică funcționalitate de business (nu există încă) — verifică
 * exact cele trei mecanisme care, dacă se strică, se strică TĂCUT:
 *   1. `demoMode` citit prin config, nu prin env() (s-ar stinge la `config:cache`);
 *   2. clasa de temă randată server-side, înainte de primul paint (FR-PREF-03);
 *   3. preload-ul fonturilor, care depinde de o cale din manifestul Vite.
 */
class AppShellTest extends TestCase
{
    public function test_the_landing_page_renders_the_welcome_component(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Welcome')
                ->has('demoMode')
                ->has('theme')
                ->has('flash')
            );
    }

    public function test_demo_mode_comes_from_config_not_from_env(): void
    {
        // `env()` întoarce implicitul după `config:cache` (pe care entrypoint-ul de
        // producție îl rulează). Dacă prop-ul s-ar citi din env(), suprascrierea de
        // config de mai jos n-ar avea niciun efect — exact modul de eșec vizat.
        config(['throughput.demo.mode' => false]);

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('demoMode', false)
        );

        config(['throughput.demo.mode' => true]);

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('demoMode', true)
        );
    }

    public function test_dark_theme_is_rendered_on_the_html_element_without_a_cookie(): void
    {
        $response = $this->get('/');

        $response->assertSee('<html lang="en" class="dark"', false);
        $response->assertSee('color-scheme: dark', false);
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('theme', 'dark'));
    }

    public function test_the_theme_cookie_switches_the_class_server_side(): void
    {
        // Cookie NECRIPTAT, cum îl scrie JS-ul din `document.cookie`: dacă `theme` ar
        // ieși din excepția lui EncryptCookies, valoarea ar fi respinsă, iar tema ar
        // reveni la închis după fiecare navigare — licărirea interzisă de FR-PREF-03.
        $response = $this->withUnencryptedCookie('theme', 'light')->get('/');

        $response->assertSee('color-scheme: light', false);
        $response->assertDontSee('<html lang="en" class="dark"', false);
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('theme', 'light'));
    }

    public function test_both_first_paint_fonts_are_preloaded_with_hashed_urls(): void
    {
        $response = $this->get('/');

        // Hash-urile vin din manifestul Vite, deci nu se compară literal — se verifică
        // doar că preload-ul există pentru ambele fețe și că a trecut prin manifest.
        $response->assertSee('rel="preload" as="font" type="font/woff2"', false);
        $response->assertSee('ibm-plex-sans-latin-400-normal-', false);
        $response->assertSee('ibm-plex-mono-latin-400-normal-', false);
    }
}
