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
 *     încredere despre preferința de sistem a vizitatorului PE ACEST RĂSPUNS.
 *
 *     Corecție (review, P2-004 din 2026-09-13; afirmația anterioară de aici era falsă):
 *     Chromium (Chrome/Edge/Opera, de la Chrome 93) LIVREAZĂ `Sec-CH-Prefers-Color-Scheme`,
 *     opt-in prin `Accept-CH`/`Critical-CH` — Firefox și Safari nu. Client Hints tot nu se
 *     implementează aici, dar deliberat, nu din necunoaștere: decizia proprietarului
 *     (2026-09-13) face din DARK implicitul fix pentru cine n-a ales nimic, deci „System"
 *     nu mai e stare implicită pentru nimeni — apare doar la cine o alege EXPLICIT din
 *     comutator, pe un ecran deja autentificat, unde `matchMedia` client-side oricum
 *     rulează imediat după primul paint. Client Hints ar elimina o licărire care, pentru
 *     acest subset de utilizatori, nu mai există ca problemă de prim rang.
 *
 * Ordinea de rezoluție (vezi `resolveForRequest()`) NU mai pune cookie-ul înaintea
 * alegerii explicite a utilizatorului curent — asta era P2-004: pe același browser,
 * cookie-ul lăsat de un cont anterior (ex. Owner → Dark) putea suprascrie vizual
 * alegerea `light` explicită a contului următor (ex. Manager), la primul răspuns
 * server-side, exact fluxul de login succesiv din specs.md §7.3.
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
     * Ordine de rezoluție (corectată, P2-004):
     *   1. Alegerea EXPLICITĂ (`light`/`dark`) a utilizatorului autentificat curent —
     *      câștigă mereu, indiferent de ce cookie de browser vine pe cerere. E singurul
     *      semnal legat de PERSOANA care face cererea (FR-PREF-02); cookie-ul e legat de
     *      BROWSER-ul din care poate fi autentificat oricine, succesiv (conturile demo).
     *   2. Cookie-ul, dacă poartă o valoare rezolvată validă — acoperă „System" (alegerea
     *      utilizatorului nu fixează `light`/`dark`, deci pasul 1 nu se aplică) și
     *      vizitatorul anonim, care nu are deloc o alegere de verificat la pasul 1.
     *   3. Orice alt caz (vizitator anonim fără cookie, sau „System" pe un dispozitiv
     *      care nu a rulat încă JS-ul de corecție): niciun semnal disponibil server-side —
     *      implicit ÎNCHISĂ. Decizia proprietarului (2026-09-13) o face FIXĂ pentru cine
     *      n-a ales nimic, nu doar „regula pentru absența unei preferințe de sistem" cum
     *      spunea FR-PREF-01 inițial — vezi §15.6.
     */
    public static function resolveForRequest(Request $request): string
    {
        $user = $request->user();

        if ($user !== null && in_array($user->theme, self::RESOLVED, true)) {
            return $user->theme;
        }

        $cookie = $request->cookie(self::COOKIE_NAME);

        if (in_array($cookie, self::RESOLVED, true)) {
            return $cookie;
        }

        return 'dark';
    }
}
