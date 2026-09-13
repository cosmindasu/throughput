<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Preferences\UpdateThemeRequest;
use App\Support\ThemePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * `PATCH /preferences/theme` — FR-PREF-01…03, BR-PREF-01…03. Fără workspace în cale
 * (routes/web/preferences.php): preferința e a persoanei, nu a organizației (FR-PREF-02).
 */
class ThemeController extends Controller
{
    public function update(UpdateThemeRequest $request): RedirectResponse
    {
        $choice = $request->string('theme')->value();

        // ALEGEREA persistă pe `users.theme`, indiferent de rezoluție (FR-PREF-02).
        $request->user()->forceFill(['theme' => $choice])->save();

        $resolved = match ($choice) {
            'light', 'dark' => $choice,
            // „System": clientul a rezolvat deja preferința reală a SO prin `matchMedia`
            // (singurul semnal de încredere — vezi App\Support\ThemePreference) și o
            // trimite explicit, ca trecerea LA System să nu licărească la reîncărcarea
            // imediată. Fără el (client fără JS, sau test), păstrează rezoluția
            // curentă din cookie, altfel cade pe implicitul închis din ThemePreference.
            default => $request->string('resolvedTheme')->value()
                ?: $request->cookie(ThemePreference::COOKIE_NAME)
                ?: 'dark',
        };

        Cookie::queue(
            ThemePreference::COOKIE_NAME,
            $resolved,
            60 * 24 * 365, // minute — un an, ca stub-ul Sprint 0 (resources/js/hooks/useThemeSync.ts)
            '/',
            null,
            (bool) config('session.secure'),
            false, // httpOnly=false — citit/scris în clar din JS; bootstrap/app.php exceptează EncryptCookies.
            false,
            (string) config('session.same_site', 'lax'),
        );

        return back();
    }
}
