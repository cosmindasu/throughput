<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDemoModeGuardrails;
use App\Models\User;
use App\Support\DemoMode;
use App\Support\Permissions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * §22.2 — subsetul distructiv al guardrail-urilor DEMO_MODE, obligatoriu la publicare
 * (plan §8, checklist §15). Verificat pe Owner: BR-DEMO-01 spune „indiferent de rol", deci
 * rolul cu toate drepturile e singurul pe care refuzul demonstrează ceva.
 */
class DemoModeGuardrailsTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        // Ecranul de ștergere a workspace-ului vine în Faza 5. Sonda poartă numele fixat în
        // DemoMode::GUARDED_ACTIONS, deci testează exact ce va proteja ruta reală.
        Route::middleware(['web', 'auth', 'session.context', 'workspace'])
            ->delete('/{workspace}', fn () => response('deleted'))
            ->name('workspace.destroy');

        Route::getRoutes()->refreshNameLookups();
    }

    public function test_deleting_a_workspace_is_refused_in_demo_mode_even_for_an_owner(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)->delete('/marlin')
            ->assertForbidden()
            ->assertSee(DemoMode::refusal('workspace.delete'));
    }

    public function test_from_an_inertia_page_the_refusal_comes_back_as_a_flash_message(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->from('/marlin/settings')
            ->delete('/marlin', [], ['X-Inertia' => 'true'])
            ->assertRedirect('/marlin/settings')
            ->assertSessionHas('error', DemoMode::refusal('workspace.delete'));
    }

    public function test_the_same_route_runs_when_demo_mode_is_off(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)->delete('/marlin')->assertOk()->assertSee('deleted');
    }

    public function test_the_can_prop_source_follows_the_same_rule_as_the_server(): void
    {
        config(['throughput.demo.mode' => true]);
        $this->assertFalse(DemoMode::allows('workspace.delete'));

        config(['throughput.demo.mode' => false]);
        $this->assertTrue(DemoMode::allows('workspace.delete'));
    }

    public function test_the_absolute_bulk_row_cap_applies_only_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true, 'throughput.limits.bulk_max_rows' => 60_000]);

        $this->assertFalse(DemoMode::exceedsBulkRowCap(60_000));
        $this->assertTrue(DemoMode::exceedsBulkRowCap(60_001));

        config(['throughput.demo.mode' => false]);

        $this->assertFalse(DemoMode::exceedsBulkRowCap(1_000_000));
    }

    public function test_every_web_route_passes_through_the_guardrail(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }

            $this->assertContains(
                EnsureDemoModeGuardrails::class,
                app(Router::class)->gatherRouteMiddleware($route),
                "Ruta `{$route->uri()}` ocolește guardrail-urile DEMO_MODE."
            );
        }
    }

    public function test_a_workspace_deletion_route_cannot_appear_under_an_unguarded_name(): void
    {
        // Regresia realistă: ecranul din Faza 5 își numește ruta `settings.workspace.destroy`,
        // registrul rămâne pe `workspace.destroy`, iar guardrail-ul nu se mai aplică nicăieri,
        // fără nicio eroare.
        $guarded = collect(DemoMode::GUARDED_ACTIONS)->pluck('routes')->flatten()->all();

        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('DELETE', $route->methods(), true))
            ->filter(fn (RoutingRoute $route) => $route->uri() === '{workspace}'
                || str_ends_with((string) $route->getName(), 'workspace.destroy')
                || str_ends_with((string) $route->getName(), 'workspaces.destroy'))
            ->reject(fn (RoutingRoute $route) => in_array($route->getName(), $guarded, true))
            ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
            ->values();

        $this->assertSame([], $unguarded->all(), 'Rute de ștergere a workspace-ului în afara DemoMode::GUARDED_ACTIONS: '.$unguarded->implode(', '));
    }
}
