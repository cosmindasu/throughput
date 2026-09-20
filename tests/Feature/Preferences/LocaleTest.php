<?php

namespace Tests\Feature\Preferences;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\App;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-01 — `PATCH /preferences/locale` prin lanțul real de
 * middleware (`auth → session.context`), pe modelul exact al `ThemeTest` (§15.6). Testele
 * Laravel NU moștenesc cookie-urile din răspunsul anterior, deci fiecare pas care depinde
 * de cookie-ul scris de pasul precedent îl citește explicit din răspuns și îl retrimite —
 * exact tehnica din ThemeTest.
 */
class LocaleTest extends TestCase
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

    public function test_a_new_user_defaults_to_english_when_no_choice_was_ever_made(): void
    {
        // `User::factory()` nu setează `locale` — verifică implicitul de coloană
        // (migrația `2026_09_20_110000_add_locale_to_users_table`), nu cod aplicativ.
        // `fresh()`, nu instanța din `create()`: Eloquent nu re-citește rândul după
        // INSERT, deci `locale` ar rămâne `null` în memorie — DEFAULT-ul e vizibil doar
        // dintr-o citire reală.
        $user = User::factory()->create()->fresh();

        $this->assertSame('en', $user->locale);
    }

    public function test_resolution_level_1_the_authenticated_users_explicit_choice_wins_over_the_cookie(): void
    {
        // Nivelul 1 din App\Support\LocalePreference — la fel ca P2-004 pentru temă:
        // alegerea EXPLICITĂ a utilizatorului curent câștigă mereu, indiferent de cookie-ul
        // de browser lăsat de un cont anterior (login succesiv, conturi demo, §7.3).
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $response = $this->withUnencryptedCookie('locale', 'en')
            ->actingAs($this->owner)
            ->get('/marlin/dashboard');

        $response->assertSee('<html lang="fr"', false);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'fr')
            ->where('auth.user.locale', 'fr')
        );
    }

    public function test_resolution_level_2_the_cookie_is_used_for_a_guest_without_a_session_choice(): void
    {
        // Nivelul 2 — niciun utilizator autentificat de verificat la pasul 1: vizitatorul
        // neautentificat rezolvă direct din cookie.
        $response = $this->withUnencryptedCookie('locale', 'fr')->get('/login');

        $response->assertSee('<html lang="fr"', false);
    }

    public function test_resolution_level_3_falls_back_to_app_locale_when_nothing_else_resolves(): void
    {
        // Nivelul 3 — niciun cookie, niciun utilizator autentificat: implicitul
        // `config('app.locale')`, NU o valoare hardcodată în LocalePreference — schimbat
        // aici explicit ca să nu poată fi confundat cu un `'en'` scris direct în clasă.
        config(['app.locale' => 'fr']);

        $response = $this->get('/login');

        $response->assertSee('<html lang="fr"', false);
    }

    public function test_accept_language_header_does_not_influence_resolution(): void
    {
        // FR-I18N-01 — interzicere explicită: antetul Accept-Language NU e sursă de
        // rezoluție, spre deosebire de o detecție automată „obișnuită". Un vizitator fără
        // cookie și fără sesiune vede tot implicitul `en`, indiferent de limba browser-ului.
        $response = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->get('/login');

        $response->assertSee('<html lang="en"', false);

        // Simetric: un cookie explicit `en` nu e răsturnat de un Accept-Language francez.
        $response = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->withUnencryptedCookie('locale', 'en')
            ->get('/login');

        $response->assertSee('<html lang="en"', false);
    }

    public function test_the_preference_survives_reload_and_workspace_switching(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->patch('/preferences/locale', ['locale' => 'fr'])
            ->assertPlainCookie('locale', 'fr');

        // Alt workspace, ACEEAȘI alegere: preferința de limbă e a persoanei, nu a
        // organizației (FR-I18N-01, simetric cu FR-PREF-02) — nu se resetează la comutare.
        $this->withUnencryptedCookie('locale', 'fr')
            ->actingAs($this->owner)
            ->get('/cascade/dashboard')
            ->assertSee('<html lang="fr"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.locale', 'fr')
                ->where('locale', 'fr')
            );
    }

    public function test_updating_the_locale_persists_the_choice_and_the_mirror_cookie(): void
    {
        $this->actingAs($this->owner)
            ->patch('/preferences/locale', ['locale' => 'fr'])
            ->assertRedirect()
            ->assertPlainCookie('locale', 'fr');

        $this->assertSame('fr', $this->owner->fresh()->locale);
    }

    public function test_an_invalid_locale_value_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patch('/preferences/locale', ['locale' => 'de'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('en', $this->owner->fresh()->locale);
    }

    public function test_a_viewer_can_switch_locale(): void
    {
        // La fel ca la temă (BR-PREF-02): comutarea limbii nu e o acțiune de scriere de
        // business, disponibilă tuturor rolurilor.
        $viewer = $this->makeMember($this->tenant, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)
            ->patch('/preferences/locale', ['locale' => 'fr'])
            ->assertRedirect();

        $this->assertSame('fr', $viewer->fresh()->locale);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->patch('/preferences/locale', ['locale' => 'fr'])
            ->assertRedirect('/login');
    }

    public function test_the_middleware_sets_the_application_locale_before_the_response_is_built(): void
    {
        // App\Http\Middleware\SetLocale — verifică efectul concret al `App::setLocale()`,
        // nu doar propul Inertia/HTML: dacă acest test ar pica dar cele de mai sus ar
        // trece, ar însemna că middleware-ul nu rulează deloc, doar că LocalePreference
        // funcționează izolat.
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->owner)->get('/marlin/dashboard');

        $this->assertSame('fr', App::getLocale());
    }

    public function test_preferred_locale_feeds_the_native_laravel_notification_localization_contract(): void
    {
        // ADR-022, „Consecințe", pct. 3 / FR-I18N-05 — fără acest contract, `users.locale`
        // n-are niciun efect automat pe notificări/email-uri (`Mailable::locale()`).
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $this->assertSame('fr', $this->owner->preferredLocale());
    }
}
