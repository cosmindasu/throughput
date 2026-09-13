<?php

use App\Http\Middleware\EnsureDemoModeGuardrails;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexHeaders;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetSessionContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,

            // FR-PUB-04 — jumătatea de antet (`X-Robots-Tag`); meta tag-ul e în
            // resources/views/app.blade.php. Amândouă, pe toate paginile, inclusiv cele
            // autentificate: un demo public indexat de Google e o problemă de reputație,
            // nu una de trafic.
            NoIndexHeaders::class,

            // Cerut de FR-PUB-05 (specs.md §4.5): la resetarea parolei se apelează
            // `Auth::logoutOtherDevices()` NECONDIȚIONAT. Fără AuthenticateSession activ
            // global, apelul rulează dar nu invalidează nimic — celelalte sesiuni rămân
            // valide, tăcut. Adică fix genul de „securitate care pare implementată".
            AuthenticateSession::class,

            // §22.2 — acțiunile distructive oprite în DEMO_MODE, după numele rutei. Global pe
            // `web`, nu pe grupul cu workspace: un guardrail care depinde de grupul pe care
            // ajunge o rută nouă e un guardrail care se poate uita.
            EnsureDemoModeGuardrails::class,

            // §20.2 — CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy.
            SecurityHeaders::class,
        ]);

        // ADR-014, pct. 3 — ordinea e semnificativă: `Authenticate → SetSessionContext →
        // ResolveWorkspace`. Inversarea ultimelor două NU dă nicio eroare; dă un comutator
        // de workspace gol, pentru că `memberships` devine invizibil fără `app.user_id`.
        // De aceea ordinea e verificată de MiddlewareOrderTest, nu doar de comentariul ăsta.
        //
        // Aliasuri, nu un grup global: tranzacția deschisă de SetSessionContext n-are ce
        // căuta pe rutele publice (landing, login), unde nu există nici utilizator, nici
        // tenant de scopat.
        $middleware->alias([
            'session.context' => SetSessionContext::class,
            'workspace' => ResolveWorkspace::class,
        ]);

        // Aceeași ordine, față de `SubstituteBindings`: un parametru de rută tipizat
        // (`show(Account $account)`) se rezolvă printr-o interogare Eloquent, deci are
        // nevoie de tranzacție și de tenant. Framework-ul sortează doar middleware-urile din
        // lista lui de prioritate; `SubstituteBindings` e acolo și stă în grupul `web`,
        // ale noastre nu — deci, fără liniile de mai jos, binding-ul ar rula ÎNAINTEA lor
        // și fiecare pagină de detaliu ar da 500 (TenantContextMissingException).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetSessionContext::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveWorkspace::class);

        // Cookie-ul `theme` trebuie citit/scris în clar din JS
        // (document.cookie, resources/js/Pages/Welcome.tsx) ȘI din Blade
        // server-side (resources/views/app.blade.php) înainte de primul
        // paint (FR-PREF-03). Criptarea implicită Laravel l-ar face
        // ilizibil pentru client, iar EncryptCookies ar arunca valoarea
        // necriptată trimisă de browser înapoi — exact bug-ul care ar
        // strica randarea fără licărire.
        $middleware->encryptCookies(except: ['theme']);

        // În producție, aplicația stă în spatele Traefik-ului Coolify, care termină
        // TLS-ul și vorbește HTTP cu containerul nginx. Fără proxy-uri de încredere,
        // Laravel vede cererea ca `http`: redirecturile și URL-urile generate ies pe
        // http, deși `APP_URL` e https — conținut mixt și redirecturi rupte la login.
        // `at: '*'` e sigur AICI fiindcă nginx nu publică niciun port: singura cale
        // către container e rețeaua internă Docker, prin Traefik (§4).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
