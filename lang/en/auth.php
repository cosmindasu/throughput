<?php

/**
 * PUBLICAT din framework, VERBATIM (ADR-022, specs.md §15.8 FR-I18N-04) — array-ul de mai
 * jos e copie exactă a lui `vendor/laravel/framework/src/Illuminate/Translation/lang/en/
 * auth.php`, verificată cu `===`, nu pe octeți. Nu edita valorile engleze: fișierul există
 * ca să AIBĂ o pereche franceză (`lang/fr/auth.php`), nu ca să schimbe engleza.
 *
 * De ce a trebuit publicat: Laravel livrează DOAR engleză. Fără fișierul ăsta și perechea
 * lui, `App::setLocale('fr')` lăsa mesajele de autentificare în engleză, tăcut — măsurat:
 * `__('auth.failed')` sub `fr` întorcea „These credentials do not match our records."
 *
 * Ambele chei folosite aici sunt LIVE, nu teoretice: `App\Http\Requests\Auth\LoginRequest`
 * le aruncă prin `ValidationException` pe formularul de login (`failed` la credențiale
 * greșite, `throttle` la a 6-a încercare). `password` vine cu regula `current_password`,
 * pe care proiectul n-o folosește azi — păstrată fiindcă fișierul e copie verbatim.
 *
 * CUM SE ÎMBINĂ cu fișierul din framework, fiindcă de asta depinde procedura de upgrade:
 * `TranslationServiceProvider::registerLoader()` construiește `FileLoader` cu DOUĂ căi, în
 * ordinea `[vendor/…/Translation/lang, lang/]`, iar `FileLoader::loadPaths()` le îmbină cu
 * `array_replace_recursive`. Deci fișierul de aici NU înlocuiește fișierul din framework —
 * îl suprascrie CHEIE CU CHEIE. O cheie pe care framework-ul o are și noi nu, se rezolvă în
 * continuare, din `vendor/`.
 *
 * Consecința la un upgrade de Laravel, și motivul pentru care nu e de ajuns să nu faci
 * nimic: o cheie NOUĂ adăugată de framework se va rezolva tăcut în ENGLEZĂ pe interfața
 * franceză — engleza merge (vine din `vendor/`), franceza cade pe fallback, iar
 * `php artisan i18n:coverage` NU o vede, fiindcă lipsește din amândouă fișierele noastre,
 * deci rămâne simetrică. Exact tiparul „gaură invizibilă" pe care FR-I18N-02 îl descrie.
 * **Procedura: după fiecare upgrade, compară acest fișier cu cel din `vendor/` și adaugă
 * cheile noi în AMBELE limbi.** Nimic nu te avertizează dacă n-o faci.
 *
 * Același mecanism e și motivul pentru care `validation.php` a fost publicat INTEGRAL, nu
 * doar pe cele 28 de reguli folosite azi: doar cheile prezente în fișierele noastre sunt
 * vizibile gate-ului de acoperire.
 *
 * S-au publicat TREI din cele patru fișiere localizabile ale framework-ului: `validation`,
 * `auth`, `passwords`. `pagination` a fost lăsat NEPUBLICAT deliberat, nu omis: proiectul nu
 * randează niciodată vederile de paginare ale lui Laravel — `App\Support\ListQuery::paginate()`
 * întoarce un `CursorPaginator` serializat spre React, iar `->links()` nu e apelat în nicio
 * vedere Blade. Publicat, ar fi adăugat două chei pe care `i18n:coverage` le-ar păzi la
 * nesfârșit fără ca ele să poată apărea vreodată pe ecran. (Se vede ușor că îmbinarea chiar
 * funcționează așa: sub `fr`, `__('pagination.previous')` întoarce „&laquo; Previous".)
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

];
