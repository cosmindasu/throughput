<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Sursă unică pentru rezolvarea limbii interfeței (ADR-022, specs.md §15.8, FR-I18N-01).
 *
 * Pe modelul EXACT al lui `App\Support\ThemePreference` (plan-implementare.md, „Lot I18N",
 * Val 1) — aceeași separare între ALEGERE și REZOLVARE, cu o simplificare: spre deosebire
 * de temă, aici nu există o a treia stare de tip „System". `users.locale` e `en`/`fr`, iar
 * cookie-ul `locale` poartă aceeași mulțime de valori — alegerea ȘI rezoluția coincid
 * mereu, când alegerea e validă (pasul 1 mai jos).
 *
 * Ordinea de rezoluție (FR-I18N-01, fixată și non-negociabilă):
 *   1. Alegerea EXPLICITĂ a utilizatorului autentificat curent (`users.locale`), dacă e
 *      validă — la fel ca la temă (P2-004): câștigă mereu, indiferent de cookie-ul
 *      browser-ului, fiindcă e singurul semnal legat de PERSOANA care face cererea, nu de
 *      browser-ul din care poate fi autentificat oricine, succesiv (conturile demo).
 *   2. Cookie-ul `locale`, dacă poartă o valoare validă — acoperă vizitatorul neautentificat
 *      și randarea server-side dinaintea oricărei sesiuni.
 *   3. `APP_LOCALE` (implicit `en`, config('app.locale')) — nimic altceva. Antetul
 *      `Accept-Language` NU se folosește ca sursă: interzis explicit de FR-I18N-01, cu
 *      motivul că ar contrazice engleza ca implicit deliberat și ar produce randări
 *      inconsistente pentru evaluatori care deschid demo-ul din browsere cu limbi diferite
 *      de cea pe care aleg s-o vadă. `ThemePreference::resolveForRequest()` nu are nici el
 *      vreun pas echivalent cu un antet de browser — „modelul exact" înseamnă și asta.
 *
 * `resources/views/app.blade.php` (randarea inițială, `<html lang>`, fără prop Inertia
 * încă) și `HandleInertiaRequests::share()` (propul `locale`) apelează AMÂNDOUĂ aici, ca
 * cele două să nu poată diverge silențios — exact mecanismul care evită dubla sursă de
 * adevăr documentat la temă.
 */
final class LocalePreference
{
    public const COOKIE_NAME = 'locale';

    /** @var list<string> */
    public const CHOICES = ['en', 'fr'];

    /**
     * Limba de aplicat ACUM, pentru acest răspuns (`App::setLocale()`, `<html lang>`,
     * propul Inertia `locale`).
     */
    public static function resolveForRequest(Request $request): string
    {
        $user = $request->user();

        if ($user !== null && in_array($user->locale, self::CHOICES, true)) {
            return $user->locale;
        }

        $cookie = $request->cookie(self::COOKIE_NAME);

        if (in_array($cookie, self::CHOICES, true)) {
            return $cookie;
        }

        return (string) config('app.locale', 'en');
    }
}
