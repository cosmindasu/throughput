<?php

namespace App\Http\Middleware;

use App\Support\LocalePreference;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-022, specs.md §15.8 FR-I18N-01 — punct UNIC care fixează `App::setLocale()`,
 * pe modelul `SetSessionContext`/`ResolveWorkspace` (.ai/rules/tenancy.md): un singur loc
 * care setează limba, nu N controllere care presupun implicitul.
 *
 * Înregistrat GLOBAL pe grupul `web` din `bootstrap/app.php`, NU în spatele lui `auth`:
 * `LocalePreference::resolveForRequest()` citește `$request->user()`, care reflectă
 * corect utilizatorul autentificat din sesiune indiferent dacă middleware-ul `auth` a
 * rulat pe ruta curentă (la fel cum `ThemePreference::resolveForRequest()`, apelat din
 * `HandleInertiaRequests::share()`, funcționează identic pe rutele publice — login,
 * resetare parolă — unde mesajele de validare/flash trebuie localizate la fel ca pe
 * rutele autentificate, FR-I18N-04).
 *
 * Rulează ÎNAINTEA oricărui `trans()`/`__()`/`Lang::get()` folosit de validare sau flash:
 * `FormRequest::rules()`/`messages()` se evaluează la rezolvarea controller-ului, adică
 * la CAPĂTUL pipeline-ului de middleware — orice poziție în lista `web` e „devreme"
 * pentru asta. Poziționat totuși ÎNAINTEA lui `HandleInertiaRequests` (bootstrap/app.php),
 * ca propul `locale` din `share()` să nu poată fi citit vreodată cu limba veche, dacă
 * `share()` ar ajunge să apeleze `trans()` pe vreo cheie comună.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(LocalePreference::resolveForRequest($request));

        return $next($request);
    }
}
