<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Sursă unică pentru rezolvarea temei (specs.md §15.6, FR-PREF-01…03).
 *
 * Două noțiuni distincte, deliberat neconfundate (plan §8):
 *   - ALEGEREA (`users.theme`): `system` | `light` | `dark`, per utilizator, persistă
 *     la comutarea workspace-ului (FR-PREF-02).
 *   - REZOLVAREA (cookie-ul `theme`, necriptat — vezi excepția din bootstrap/app.php):
 *     `light` | `dark`, ce se randează efectiv pe `<html>` (FR-PREF-03). Pentru
 *     alegerile explicite, rezolvarea e trivială (= alegerea). Pentru „System", cookie-ul
 *     poartă ULTIMA valoare cunoscută rezolvată de CLIENT prin `matchMedia`
 *     (resources/js/hooks/useThemeSync.ts) — server-ul nu are niciun semnal de
 *     încredere despre preferința de sistem a vizitatorului. Niciun browser major nu
 *     livrează încă `Sec-CH-Prefers-Color-Scheme` (propunere WICG, nefinalizată la data
 *     scrierii) — de asta rezolvarea nu se face prin Client Hints, ci client-side.
 *
 * `resources/views/app.blade.php` (randare inițială, fără prop Inertia încă) și
 * `HandleInertiaRequests::share()` (propul `theme`) apelează AMÂNDOUĂ aici, ca cele
 * două să nu poată diverge silențios.
 */
final class ThemePreference
{
    public const COOKIE_NAME = 'theme';

    /** @var list<string> */
    public const CHOICES = ['system', 'light', 'dark'];

    /** @var list<string> */
    public const RESOLVED = ['light', 'dark'];

    /**
     * Clasa/valoarea de randat ACUM pe `<html>`, pentru acest răspuns.
     *
     * Ordine de rezoluție:
     *   1. Cookie-ul, dacă poartă o valoare rezolvată validă — sursa de adevăr pentru
     *      „fără licărire", scrisă fie direct (alegere explicită), fie de clientul care
     *      a rezolvat deja „System" prin `matchMedia`.
     *   2. Fără cookie, dar utilizator autentificat cu alegere EXPLICITĂ (light/dark):
     *      dispozitiv nou, cont vechi — FR-PREF-03, cazul tratat explicit în plan §8.
     *   3. Orice alt caz (vizitator anonim fără cookie, sau alegere „System" pe un
     *      dispozitiv care nu a rulat încă JS-ul de corecție): niciun semnal de sistem
     *      disponibil server-side — implicit ÎNCHISĂ, exact regula pe care FR-PREF-01 o
     *      dă pentru absența oricărei preferințe de sistem, generalizată la absența
     *      CAPACITĂȚII de a o detecta din request.
     */
    public static function resolveForRequest(Request $request): string
    {
        $cookie = $request->cookie(self::COOKIE_NAME);

        if (in_array($cookie, self::RESOLVED, true)) {
            return $cookie;
        }

        $user = $request->user();

        if ($user !== null && in_array($user->theme, self::RESOLVED, true)) {
            return $user->theme;
        }

        return 'dark';
    }
}
