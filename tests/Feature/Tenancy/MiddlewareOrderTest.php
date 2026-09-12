<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetSessionContext;
use App\Models\Membership;
use App\Services\Tenancy\TenantContext;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan §7.9 — al patrulea test „care ar fi prins un bug real".
 *
 * `Authenticate → SetSessionContext → ResolveWorkspace`. Inversarea ultimelor două nu dă
 * nicio eroare de sintaxă și nicio excepție evidentă la citirea codului: `memberships`
 * devine pur și simplu invizibil, pentru că politica lui se sprijină pe `app.user_id`.
 */
class MiddlewareOrderTest extends TestCase
{
    public function test_the_session_context_middleware_runs_before_the_workspace_resolver(): void
    {
        $route = Route::getRoutes()->getByName('workspace.dashboard');

        $this->assertNotNull($route, 'Ruta de dashboard cu workspace în cale lipsește.');

        $middleware = app(Router::class)->gatherRouteMiddleware($route);

        $session = array_search(SetSessionContext::class, $middleware, true);
        $workspace = array_search(ResolveWorkspace::class, $middleware, true);

        $this->assertNotFalse($session, 'SetSessionContext nu e înregistrat pe rutele de workspace.');
        $this->assertNotFalse($workspace, 'ResolveWorkspace nu e înregistrat pe rutele de workspace.');
        $this->assertLessThan($workspace, $session, 'SetSessionContext trebuie să ruleze ÎNAINTEA lui ResolveWorkspace (ADR-014).');
    }

    public function test_every_workspace_route_carries_both_middlewares(): void
    {
        // Regresia realistă nu e reordonarea, ci ruta nouă adăugată în Faza 3 direct pe
        // grupul greșit.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), '{workspace}'));

        $this->assertGreaterThan(0, $routes->count());

        foreach ($routes as $route) {
            $middleware = app(Router::class)->gatherRouteMiddleware($route);

            $this->assertContains(SetSessionContext::class, $middleware, "Ruta `{$route->uri()}` nu deschide contextul de sesiune.");
            $this->assertContains(ResolveWorkspace::class, $middleware, "Ruta `{$route->uri()}` nu rezolvă workspace-ul.");
        }
    }

    public function test_with_the_order_reversed_the_workspace_becomes_unreachable(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $user = $this->makeMember($tenant, 'demo.owner@throughput.dev');

        Route::middleware(['web', 'auth', 'workspace', 'session.context'])
            ->get('/{workspace}/reversed-order-probe', fn () => response('ok'))
            ->name('probe.reversed');

        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($user)->get("/{$tenant->slug}/reversed-order-probe");

        // Planul prevedea „niciun mesaj de eroare, doar un comutator gol". Cu implementarea
        // de față eșecul e mai zgomotos — `ResolveWorkspace` nu găsește niciun membership
        // (nu există `app.user_id`) și dă 404 în loc să continue cu o listă goală. Mai bine
        // așa: un 404 se observă, un comutator gol se ignoră. Testul fixează comportamentul
        // ca să nu redevină tăcut printr-o „îmbunătățire" ulterioară.
        $this->assertSame(404, $response->getStatusCode());

        // Iar cu ordinea corectă — adică exact ce face `SetSessionContext` înainte de
        // `ResolveWorkspace`: deschide tranzacția și setează `app.user_id` — aceeași
        // pereche user/tenant chiar vede membership-ul. Diferența dintre 404 și pagină
        // e fix linia asta.
        $this->assertSame(1, TenantContext::openFor(
            $user->getKey(),
            fn () => Membership::forCurrentUserAcrossTenants($user->getKey())->count()
        ));
    }
}
