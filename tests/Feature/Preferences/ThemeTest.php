<?php

namespace Tests\Feature\Preferences;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FR-PREF-01…03, BR-PREF-01…03 (specs.md §15.6) — `PATCH /preferences/theme` prin
 * lanțul real de middleware (`auth → session.context`), nu apeluri directe la
 * `App\Support\ThemePreference`. Testele Laravel NU moștenesc cookie-urile din
 * răspunsul anterior (`MakesHttpRequests::prepareCookiesForRequest()` citește doar
 * `withCookie()`/`withUnencryptedCookie()`), deci fiecare pas care depinde de cookie-ul
 * scris de pasul precedent îl citește explicit din răspuns și îl retrimite.
 */
class ThemeTest extends TestCase
{
    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_each_of_the_three_states_is_persisted_and_renders_the_correct_html_class_on_the_next_request(): void
    {
        // Light — rezoluție trivială: alegerea E valoarea rezolvată (FR-PREF-03).
        $this->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'light'])
            ->assertRedirect()
            ->assertPlainCookie('theme', 'light');

        $this->assertSame('light', $this->owner->fresh()->theme);

        $this->withUnencryptedCookie('theme', 'light')
            ->actingAs($this->owner)
            ->get('/marlin/dashboard')
            ->assertSee('color-scheme: light', false)
            ->assertDontSee('<html lang="en" class="dark"', false);

        // Dark.
        $this->withUnencryptedCookie('theme', 'light')
            ->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'dark'])
            ->assertPlainCookie('theme', 'dark');

        $this->assertSame('dark', $this->owner->fresh()->theme);

        $this->withUnencryptedCookie('theme', 'dark')
            ->actingAs($this->owner)
            ->get('/marlin/dashboard')
            ->assertSee('<html lang="en" class="dark"', false);

        // System, cu rezoluția clientului (`matchMedia`, ThemeToggle.tsx → aici
        // simulată drept „light"): cookie-ul poartă REZOLVAREA, `users.theme` poartă
        // ALEGEREA — cele două nu se confundă (FR-PREF-03).
        $this->withUnencryptedCookie('theme', 'dark')
            ->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'system', 'resolvedTheme' => 'light'])
            ->assertPlainCookie('theme', 'light');

        $this->assertSame('system', $this->owner->fresh()->theme);

        $this->withUnencryptedCookie('theme', 'light')
            ->actingAs($this->owner)
            ->get('/marlin/dashboard')
            ->assertDontSee('<html lang="en" class="dark"', false);
    }

    public function test_system_without_a_client_resolution_falls_back_to_the_documented_dark_default(): void
    {
        // Niciun cookie anterior, niciun `resolvedTheme` trimis — clientul fără JS
        // (sau un test). FR-PREF-01, aplicat literal: absența preferinței de sistem
        // rezolvă la tema închisă (App\Support\ThemePreference).
        $this->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'system'])
            ->assertPlainCookie('theme', 'dark');

        $this->assertSame('system', $this->owner->fresh()->theme);
    }

    public function test_the_preference_survives_reload_and_workspace_switching(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'light'])
            ->assertPlainCookie('theme', 'light');

        // Alt workspace, ACELAȘI cookie de browser: alegerea nu se resetează la
        // comutare — e a persoanei, nu a organizației (FR-PREF-02).
        $this->withUnencryptedCookie('theme', 'light')
            ->actingAs($this->owner)
            ->get('/cascade/dashboard')
            ->assertSee('color-scheme: light', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.theme', 'light')
                ->where('theme', 'light')
            );
    }

    public function test_a_viewer_can_switch_theme(): void
    {
        // BR-PREF-02 — comutarea temei nu e o acțiune de scriere de business: nu
        // verifică niciun `can`, disponibilă și rolului fără nicio scriere de business.
        $viewer = $this->makeMember($this->tenant, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)
            ->patch('/preferences/theme', ['theme' => 'dark'])
            ->assertRedirect();

        $this->assertSame('dark', $viewer->fresh()->theme);
    }

    public function test_demo_mode_does_not_block_the_theme_switch(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'dark'])
            ->assertRedirect();

        $this->assertSame('dark', $this->owner->fresh()->theme);
    }

    public function test_an_invalid_theme_value_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patch('/preferences/theme', ['theme' => 'purple'])
            ->assertSessionHasErrors('theme');

        $this->assertSame('system', $this->owner->fresh()->theme);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->patch('/preferences/theme', ['theme' => 'dark'])
            ->assertRedirect('/login');
    }
}
