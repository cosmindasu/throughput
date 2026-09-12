<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §22.2 — acțiunile distructive oprite în DEMO_MODE, indiferent de rol (BR-DEMO-01).
 *
 * Stratul de server al unei reguli cu două straturi: interfața nu randează butonul
 * (`DemoMode::allows()` în `can`), iar cererea care ajunge totuși aici — un formular vechi,
 * un `curl` — e refuzată. Decizia se ia după numele rutei, din registrul din `DemoMode`,
 * ca lista acțiunilor oprite să existe într-un singur loc.
 */
class EnsureDemoModeGuardrails
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = DemoMode::guardedActionForRoute($request->route()?->getName());

        if ($action === null || ! DemoMode::enabled()) {
            return $next($request);
        }

        // Dintr-o pagină Inertia, refuzul ajunge ca `flash.error` pe ecranul de unde a plecat
        // cererea — un 403 afișat într-un modal de eroare ar arăta ca o aplicație stricată.
        if ($request->header('X-Inertia')) {
            return back()->with('error', DemoMode::refusal($action));
        }

        abort(403, DemoMode::refusal($action));
    }
}
