<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use App\Support\DemoMode;
use App\Support\Permissions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * §22.2, rândurile cablate ABIA ÎN FAZA 5 — eliminarea/retrogradarea ultimului Owner,
 * revocarea în masă a jetoanelor API, anularea reală a abonamentului Stripe. Subsetul
 * distructiv din Faza 2 (ștergere de workspace, plafon absolut de rânduri) are testul lui,
 * `Tests\Feature\DemoModeGuardrailsTest`, neatins aici.
 *
 * Verificat pe OWNER: BR-DEMO-01 spune „indiferent de rol (inclusiv Owner)", deci rolul cu
 * toate drepturile e singurul pe care refuzul demonstrează ceva.
 *
 * Rutele reale nu există încă în acest worktree (fluxul de membri, jetoanele API și butonul
 * de anulare se construiesc în paralel sau mai târziu). Sondele de mai jos poartă EXACT
 * numele fixate în `DemoMode::GUARDED_ACTIONS`, deci testează exact ce va proteja ruta
 * reală — aceeași tehnică folosită din Faza 2 pentru `workspace.destroy`.
 */
class DemoModeGuardrailsPhase5Test extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();

        // Căile sondelor sunt DELIBERAT distincte de cele reale (`…/01/role-probe`, nu
        // `…/{membership}/role`): ruta reală a fluxului de membri, înregistrată înainte,
        // ar prinde cererea prima și ar răspunde 404 la legarea modelului, iar testul ar
        // verifica altceva decât guardrail-ul. Ce contează aici e NUMELE rutei — singurul
        // lucru după care decide `EnsureDemoModeGuardrails`.
        $this->probe('post', '/{workspace}/settings/members/01/role-probe', 'settings.members.role');
        $this->probe('delete', '/{workspace}/settings/members/01/remove-probe', 'settings.members.destroy');
        $this->probe('delete', '/{workspace}/settings/api-tokens', 'settings.api-tokens.destroy-all');
        $this->probe('post', '/{workspace}/settings/billing/cancel', 'settings.billing.cancel');

        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function guardedActions(): array
    {
        return [
            'role change' => ['post', '/marlin/settings/members/01/role-probe', 'members.change-role'],
            'member removal' => ['delete', '/marlin/settings/members/01/remove-probe', 'members.remove'],
            'revoke all tokens' => ['delete', '/marlin/settings/api-tokens', 'api-tokens.revoke-all'],
            'cancel subscription' => ['post', '/marlin/settings/billing/cancel', 'subscription.cancel'],
        ];
    }

    #[DataProvider('guardedActions')]
    public function test_the_action_is_refused_in_demo_mode_even_for_an_owner(string $method, string $uri, string $action): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)->{$method}($uri)
            ->assertForbidden()
            ->assertSee(DemoMode::refusal($action));
    }

    #[DataProvider('guardedActions')]
    public function test_from_an_inertia_page_the_refusal_comes_back_as_a_flash_message(string $method, string $uri, string $action): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->{$method}($uri, [], ['X-Inertia' => 'true'])
            ->assertRedirect('/marlin/settings/members')
            ->assertSessionHas('error', DemoMode::refusal($action));
    }

    #[DataProvider('guardedActions')]
    public function test_the_same_action_runs_when_demo_mode_is_off(string $method, string $uri, string $action): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)->{$method}($uri)->assertOk();
        $this->assertTrue(DemoMode::allows($action), "`can` ar ascunde {$action} în afara DEMO_MODE.");
    }

    /**
     * §22.2 numește doar revocarea ÎN MASĂ. Un vizitator care își revocă propriul jeton de
     * probă nu strică nimic pentru următorul — dacă ar fi blocat, ecranul de jetoane ar
     * deveni nedemonstrabil în singurul mediu unde rulează.
     *
     * Verificat pe REGISTRU, nu prin HTTP: ruta reală (`DELETE /settings/api-tokens/{apiToken}`,
     * alt lot din acest val) ar răspunde oricum 404 la legarea modelului pentru un id
     * inventat, deci un 200/403 n-ar dovedi nimic despre guardrail.
     */
    public function test_revoking_a_single_api_token_is_not_a_guarded_action(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->assertNull(DemoMode::guardedActionForRoute('settings.api-tokens.destroy'));
        $this->assertSame('api-tokens.revoke-all', DemoMode::guardedActionForRoute('settings.api-tokens.destroy-all'));
    }

    /**
     * Invitația încă neacceptată nu e un membru: revocarea ei e reversibilă (reinvită) și nu
     * poate lăsa workspace-ul fără Owner. Rămâne permisă în demo, deliberat — vezi comentariul
     * de lângă `members.remove` din `DemoMode::GUARDED_ACTIONS`.
     */
    public function test_revoking_a_pending_invitation_is_not_a_guarded_action(): void
    {
        $this->assertNull(DemoMode::guardedActionForRoute('settings.members.invitations.destroy'));
    }

    /**
     * Regresia realistă (aceeași formă ca garda de `workspace.destroy` din Faza 2): ecranul
     * real își numește ruta altfel decât s-a fixat aici, registrul rămâne pe numele vechi,
     * iar guardrail-ul nu se mai aplică nicăieri — fără nicio eroare, în niciun log.
     */
    public function test_a_guarded_action_cannot_appear_under_an_unguarded_route_name(): void
    {
        $guarded = collect(DemoMode::GUARDED_ACTIONS)->pluck('routes')->flatten()->all();

        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->filter(fn (RoutingRoute $route) => $this->looksLikeAGuardedAction((string) $route->getName()))
            ->reject(fn (RoutingRoute $route) => in_array($route->getName(), $guarded, true))
            ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
            ->values();

        $this->assertSame(
            [],
            $unguarded->all(),
            'Rute §22.2 în afara DemoMode::GUARDED_ACTIONS: '.$unguarded->implode(', '),
        );
    }

    private function looksLikeAGuardedAction(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        // Retrogradare de membru (BR-TEN-01, „eliminare SAU retrogradare"). Segmentul poate
        // continua (`settings.members.role.update`, numele real ales de fluxul de membri),
        // de-asta `(\.|$)`, nu doar `$`.
        if (preg_match('/members\.(role|update-role|change-role|demote)(\.|$)/', $name) === 1) {
            return true;
        }

        // Eliminarea unui membru EXISTENT. NU `…members.invitations.destroy` (revocarea unei
        // invitații neacceptate) — de-asta `members\.` trebuie să fie chiar înaintea verbului.
        if (preg_match('/members\.(destroy|remove)$/', $name) === 1) {
            return true;
        }

        // Revocarea ÎN MASĂ a jetoanelor — nu și revocarea unui singur jeton.
        if (str_contains($name, 'api-tokens.') && preg_match('/(all|bulk)/', $name) === 1) {
            return true;
        }

        // Anularea reală a abonamentului.
        return preg_match('/(billing|subscription)\.cancel$/', $name) === 1;
    }

    private function probe(string $method, string $uri, string $name): void
    {
        Route::middleware(['web', 'auth', 'session.context', 'workspace'])
            ->{$method}($uri, fn () => response('ran'))
            ->name($name);
    }
}
