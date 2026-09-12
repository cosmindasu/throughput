<?php

namespace Tests\Feature;

use App\Http\Middleware\NoIndexHeaders;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * FR-PUB-04 — `noindex, nofollow` pe TOATE paginile, inclusiv cele autentificate.
 *
 * Criteriul de acceptanță din specs.md §4.4 cere explicit „un test automat care listează
 * toate rutele înregistrate și le verifică pe fiecare" — nu un test pe pagina de start.
 * Motivul e practic: ruta care scapă indexării nu e niciodată cea la care te uiți, ci cea
 * adăugată peste trei săptămâni pe alt grup de middleware.
 */
class NoIndexTest extends TestCase
{
    public function test_every_registered_route_carries_the_noindex_middleware(): void
    {
        $router = app(Router::class);

        $uncovered = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => in_array($route->uri(), ['up', 'storage/{path}'], true))
            ->reject(fn ($route) => in_array(NoIndexHeaders::class, $router->gatherRouteMiddleware($route), true))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())
            ->values();

        $this->assertSame([], $uncovered->all(), 'Rute fără `noindex`: '.$uncovered->implode(', '));
    }

    public function test_the_header_and_the_meta_tag_are_both_present(): void
    {
        $response = $this->get('/');

        // Antetul acoperă și răspunsurile non-HTML (JSON, fișiere); meta tag-ul acoperă
        // crawlerele care nu se uită la antete. Specificația le cere pe amândouă.
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_authenticated_pages_are_covered_too(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $user = $this->makeMember($tenant, 'demo.owner@throughput.dev');

        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($user)->get("/{$tenant->slug}/dashboard");

        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }
}
