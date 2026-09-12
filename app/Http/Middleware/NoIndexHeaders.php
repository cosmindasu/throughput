<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-PUB-04 — producția E demo-ul public (§0), deci fiecare răspuns, autentificat sau
 * nu, trimite `X-Robots-Tag: noindex, nofollow`. Perechea ei — meta tag-ul
 * `<meta name="robots" ...>` — e în `resources/views/app.blade.php`, randat o singură
 * dată de Blade; antetul HTTP acoperă și răspunsurile JSON (Inertia, API) care nu trec
 * prin acel template.
 *
 * Înregistrat în grupul `web` din `bootstrap/app.php`. Că e pe FIECARE rută, nu doar pe
 * cele la care ne-am uitat, o verifică `NoIndexTest` — care parcurge întreaga colecție de
 * rute înregistrate, cum cere criteriul de acceptanță din specs.md §4.4.
 */
class NoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
