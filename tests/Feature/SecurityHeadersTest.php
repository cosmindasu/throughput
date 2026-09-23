<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * specs.md §20.2 — headerele HTTP obligatorii, pe checklist-ul de publicare (plan §15).
 */
class SecurityHeadersTest extends TestCase
{
    public function test_the_mandatory_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY);
    }

    /**
     * SEC-05 — hardening peste specs §20.2, nu o cerință de-acolo (vezi docblock-ul clasei).
     * `clipboard-write=(self)` rămâne permis (folosit la copierea jetonului API nou creat),
     * restul API-urilor neutilizate sunt dezactivate explicit.
     */
    public function test_permissions_policy_disables_unused_browser_apis(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Permissions-Policy', SecurityHeaders::PERMISSIONS_POLICY);

        $policy = (string) $response->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('camera=()', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
        $this->assertStringContainsString('payment=()', $policy);
        $this->assertStringContainsString('clipboard-write=(self)', $policy);
    }

    /**
     * OPS-03 — mecanismul de nonce cerut de Horizon (deblocat de SEC-01), dar scris general:
     * orice cod care apelează `Vite::useCspNonce()` mai devreme în cerere primește un
     * `script-src` cu `'nonce-<valoare>'` în plus; fără niciun apel, politica rămâne
     * IDENTICĂ, caracter cu caracter, cu constanta publică.
     *
     * Ordinea celor două asertări contează: `Illuminate\Foundation\Vite` e `singleton`, nu
     * `scoped` (vezi docblock-ul `SecurityHeaders::contentSecurityPolicy()`) — în ACELAȘI
     * test Pest/PHPUnit, containerul supraviețuiește între mai multe `$this->get()`, deci
     * varianta FĂRĂ nonce trebuie verificată ÎNAINTE de a genera unul, altfel nonce-ul
     * primului apel „s-ar scurge" în al doilea. Sub php-fpm (runtime-ul real) fiecare
     * cerere HTTP pornește un proces nou, deci scurgerea asta nu există în producție.
     */
    public function test_content_security_policy_gets_a_nonce_only_when_vite_generates_one(): void
    {
        Route::middleware(SecurityHeaders::class)->get('/__security-headers-test/no-nonce', fn () => 'ok');
        Route::middleware(SecurityHeaders::class)->get('/__security-headers-test/with-nonce', function () {
            Vite::useCspNonce();

            return 'ok';
        });

        $withoutNonce = $this->get('/__security-headers-test/no-nonce');
        $withoutNonce->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY);

        $withNonce = $this->get('/__security-headers-test/with-nonce');
        $nonce = Vite::cspNonce();
        $csp = (string) $withNonce->headers->get('Content-Security-Policy');

        $this->assertNotNull($nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $csp);
        // `style-src` NU primește nonce — vezi motivul în docblock-ul metodei testate.
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_scripts_get_no_inline_or_eval_exception(): void
    {
        // Excepția `unsafe-inline` e acceptată doar pentru stiluri (vezi middleware-ul). Una
        // pentru scripturi ar anula exact protecția pentru care există CSP-ul.
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self';/", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_every_web_route_passes_through_the_middleware(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }

            $this->assertContains(
                SecurityHeaders::class,
                app(Router::class)->gatherRouteMiddleware($route),
                "Ruta `{$route->uri()}` nu trimite headerele de securitate."
            );
        }
    }
}
