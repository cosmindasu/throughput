<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Preferences\UpdateLocaleRequest;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * `PATCH /preferences/locale` — ADR-022, specs.md §15.8 FR-I18N-01. Pe modelul exact al
 * lui `ThemeController::update` (routes/web/preferences.php). Fără workspace în cale:
 * preferința e a persoanei, nu a organizației (FR-I18N-01, simetric cu FR-PREF-02).
 */
class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->string('locale')->value();

        // ALEGEREA persistă pe `users.locale` (FR-I18N-01).
        $request->user()->forceFill(['locale' => $locale])->save();

        // Spre deosebire de `theme`, rezoluția e trivială: nu există o a treia stare de
        // tip „System" pentru care cookie-ul ar putea purta o valoare diferită de alegere
        // (vezi App\Support\LocalePreference) — cookie-ul oglindește direct alegerea, ca
        // randarea server-side următoare (fără sesiune încă restaurată, sau vizitator
        // neautentificat pe același browser) să nu arate o limbă, apoi alta.
        Cookie::queue(
            LocalePreference::COOKIE_NAME,
            $locale,
            60 * 24 * 365, // minute — un an, ca la ThemeController.
            '/',
            null,
            (bool) config('session.secure'),
            false, // httpOnly=false — citit/scris în clar, ca la ThemePreference::COOKIE_NAME.
            false,
            (string) config('session.same_site', 'lax'),
        );

        return back();
    }
}
