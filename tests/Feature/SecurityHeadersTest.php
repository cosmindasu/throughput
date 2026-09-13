<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
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
