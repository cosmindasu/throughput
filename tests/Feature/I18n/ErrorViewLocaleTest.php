<?php

namespace Tests\Feature\I18n;

use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04 — vederile de eroare ale framework-ului
 * (403/404/419/500/503). Proiectul n-are `resources/views/errors/`, deci se randează cele
 * din `vendor/`, iar ele își iau textul din `lang/{locale}.json` — stratul în care ȘIRUL
 * ENGLEZ E CHEIA (`__('Not Found')`), nu din `lang/{locale}/*.php`.
 *
 * Testul apără DOUĂ lucruri distincte, care se pot strica separat:
 *
 *  1. **Catalogul** — `lang/fr.json` conține cele opt șiruri. Păzit simetric și de
 *     `php artisan i18n:coverage`, al cărui al treilea strat a fost adăugat exact pentru
 *     fișierele astea.
 *  2. **Livrarea** — limba ajunge să fie fixată ÎNAINTE de randarea vederii. Asta NU vine
 *     de la `SetLocale`: el stă în grupul `web`, iar un 404 pe o rută inexistentă e aruncat
 *     de router înainte ca grupul să se aplice. Vine din pasul de locale înregistrat în
 *     `bootstrap/app.php` (`withExceptions` → `render`), care rulează pe calea de excepție.
 *
 * Al doilea punct e motivul pentru care testul lovește HTTP-ul și citește `<title>`-ul
 * randat, în loc să asserteze `__('Not Found', [], 'fr')`: o aserțiune pe catalog ar fi
 * trecut verde și înainte de reparație, cu utilizatorul francez văzând „Not Found".
 */
class ErrorViewLocaleTest extends TestCase
{
    private Tenant $marlin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->clearDatabaseTenantContext();
    }

    /**
     * Cazul care NU poate funcționa prin `SetLocale`: ruta nu există, deci grupul `web` nu
     * se aplică niciodată. VIZITATOR, nu utilizator autentificat — deliberat: pe calea asta
     * sesiunea nu a pornit, deci `users.locale` e oricum invizibil, iar `actingAs()` ar
     * ascunde tocmai asta (pune utilizatorul direct pe guard, fără sesiune, și ar face
     * testul să treacă verde din motivul greșit). Rămâne pasul 2 din `LocalePreference`:
     * cookie-ul `locale`, exceptat de la criptare (`bootstrap/app.php`) tocmai ca să fie
     * lizibil fără grupul `web`.
     */
    public function test_a_missing_route_renders_its_error_page_in_french_for_a_french_visitor(): void
    {
        $response = $this->withUnencryptedCookie('locale', 'fr')->get('/marlin/aceasta-ruta-nu-exista');

        $response->assertNotFound();
        $this->assertSame('Page introuvable', $this->titleOf($response));
    }

    public function test_a_missing_route_stays_in_english_without_a_locale_cookie(): void
    {
        // Simetria obligatorie: fără proba asta, testul de mai sus ar trece verde și dacă
        // cineva ar fixa franceza necondiționat pe calea de excepție.
        $response = $this->get('/marlin/aceasta-ruta-nu-exista');

        $response->assertNotFound();
        $this->assertSame('Not Found', $this->titleOf($response));
    }

    /**
     * Celălalt capăt: aici sesiunea A pornit și Policy-ul rulează la capătul pipeline-ului,
     * deci câștigă pasul 1 (`users.locale`) — fără niciun cookie. Un Viewer n-are acces la
     * jurnalul tenantului (§7.4), iar refuzul lui nu trece prin `withErrors()` ca cele din
     * `MemberRefusalLocaleTest`: ajunge chiar la vederea 403.
     */
    public function test_a_denied_screen_renders_its_403_page_in_french_for_a_french_user(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $viewer->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($viewer)->get('/marlin/activity');

        $response->assertForbidden();
        $this->assertSame('Accès refusé', $this->titleOf($response));
    }

    public function test_a_denied_screen_stays_in_english_by_default(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($viewer)->get('/marlin/activity');

        $response->assertForbidden();
        $this->assertSame('Forbidden', $this->titleOf($response));
    }

    /**
     * Vederile framework-ului pun mesajul și în `<title>`, și în corpul paginii; `<title>`
     * e ancora mai stabilă dintre cele două (corpul poartă CSS inline normalize.css).
     */
    private function titleOf(TestResponse $response): string
    {
        preg_match('/<title>(.*?)<\/title>/s', $response->getContent(), $matches);

        return trim($matches[1] ?? '');
    }
}
