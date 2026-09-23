<?php

namespace Tests\Feature\Auth;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * HTTP-03 (audit 2026-09-23) — `LoginController::create()` repeta `demoMode`, deja
 * partajat de `HandleInertiaRequests::share()` pe TOT grupul `web`, `/login` inclus
 * (ruta e sub `guest`, dar rămâne în grupul `web`, deci vede același middleware ca
 * orice altă pagină). Testele de mai jos acoperă exact ce ar rupe eliminarea propului
 * dublat: `demoMode` tot trebuie să ajungă pe pagină, sincron cu config, fără ca
 * `LoginController` să-l mai retrimită el însuși — vezi și `AppShellTest`, care
 * verifică același mecanism pe pagina publică `/`.
 */
class LoginPageTest extends TestCase
{
    public function test_the_login_page_renders_with_its_expected_props(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->has('demoMode')
                ->has('canResetPassword')
                ->has('demoAccounts')
            );
    }

    public function test_demo_mode_on_the_login_page_comes_from_the_shared_config_prop(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->get('/login')->assertInertia(
            fn (AssertableInertia $page) => $page->where('demoMode', false)
        );

        config(['throughput.demo.mode' => true]);

        $this->get('/login')->assertInertia(
            fn (AssertableInertia $page) => $page->where('demoMode', true)
        );
    }
}
