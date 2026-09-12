<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetSessionContext;
use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
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

    public function test_route_model_binding_runs_inside_the_tenant_context(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $user = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $own = TenantContext::run($marlin, fn () => $this->createAccount('Marlin Industrial Fasteners LLC', $user));
        $foreign = TenantContext::run($cascade, fn () => $this->createAccount('Cascade Hydraulics Group Inc.', $user));

        $route = Route::middleware(['web', 'auth', 'session.context', 'workspace'])
            ->get('/{workspace}/binding-probe/{account}', fn (Account $account) => response($account->name))
            ->name('probe.binding');

        // `SubstituteBindings` stă în grupul `web`, deci fără intrarea din lista de
        // prioritate (bootstrap/app.php) ar rula înaintea contextului: 500 cu
        // TenantContextMissingException pe fiecare pagină de detaliu.
        $middleware = app(Router::class)->gatherRouteMiddleware($route);
        $this->assertLessThan(
            array_search(SubstituteBindings::class, $middleware, true),
            array_search(ResolveWorkspace::class, $middleware, true),
            'Binding-ul de rută trebuie să ruleze DUPĂ ResolveWorkspace.'
        );

        $this->clearDatabaseTenantContext();

        // Parametrul tipizat primește contul, nu slug-ul workspace-ului: ResolveWorkspace
        // scoate segmentul din parametrii rutei, iar dispatcher-ul îi pasează pozițional.
        $this->actingAs($user)->get("/marlin/binding-probe/{$own->id}")
            ->assertOk()
            ->assertSee('Marlin Industrial Fasteners LLC');

        // Contul altui tenant nu există pentru binding: 404, nu 500 și nu datele lui.
        $this->actingAs($user)->get("/marlin/binding-probe/{$foreign->id}")->assertNotFound();
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

    public function test_a_reversed_declaration_is_reordered_by_the_priority_list(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $user = $this->makeMember($tenant, 'demo.owner@throughput.dev');

        // Declarate INVERS pe rută. Până în Faza 2 asta dădea 404: `ResolveWorkspace` rula
        // fără `app.user_id` și nu găsea niciun membership. De când ambele stau în lista de
        // prioritate a framework-ului (bootstrap/app.php — necesar pentru binding-ul de rută),
        // framework-ul reface ordinea indiferent cum e scrisă ruta. Garanția s-a mutat din
        // declarație în lista de prioritate, iar testul o fixează acolo: dacă cele două
        // `prependToPriorityList` sunt inversate sau șterse, cererea de mai jos redevine 404.
        $route = Route::middleware(['web', 'auth', 'workspace', 'session.context'])
            ->get('/{workspace}/reversed-order-probe', fn () => response('ok'))
            ->name('probe.reversed');

        $middleware = app(Router::class)->gatherRouteMiddleware($route);
        $this->assertLessThan(
            array_search(ResolveWorkspace::class, $middleware, true),
            array_search(SetSessionContext::class, $middleware, true),
        );

        $this->clearDatabaseTenantContext();

        $this->actingAs($user)->get("/{$tenant->slug}/reversed-order-probe")->assertOk();

        // Premisa pentru care ordinea contează rămâne adevărată: fără `app.user_id`,
        // membership-ul e invizibil (0), cu el — exact ce setează `SetSessionContext` — e
        // vizibil (1). Diferența dintre un 404 și pagină e fix această variabilă.
        $this->clearDatabaseTenantContext();

        $this->assertSame(0, DB::transaction(
            fn () => Membership::forCurrentUserAcrossTenants($user->getKey())->count()
        ));

        $this->assertSame(1, TenantContext::openFor(
            $user->getKey(),
            fn () => Membership::forCurrentUserAcrossTenants($user->getKey())->count()
        ));
    }

    private function createAccount(string $name, User $creator): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $creator->getKey();
        $account->save();

        return $account;
    }
}
