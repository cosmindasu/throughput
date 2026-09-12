<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

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
