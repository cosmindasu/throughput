<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

/**
 * OPS-03 (audit 2026-09-23) — accesul la dashboard-ul Horizon, cerut de specs.md §25.2 ca
 * instrument de monitorizare. Fără `HorizonServiceProvider`, `Horizon::check()` cădea pe
 * implicitul pachetului, `environment('local')`, deci `/horizon` dădea 403 TUTUROR în producție.
 *
 * De ce HTTP Basic cu credențiale din env, nu gate-ul clasic pe e-mailul unui utilizator:
 * - `demo:reset` rulează `migrate:fresh` în fiecare noapte (FR-DEMO-03), deci niciun cont din
 *   `users` nu supraviețuiește până a doua zi — nici al proprietarului;
 * - conturile demo au parolele PUBLICE (§22), deci nicio regulă bazată pe rolul unui utilizator
 *   autentificat nu e o barieră. Autorizarea de aici nu se uită la `$request->user()` deloc.
 *
 * Fail-closed: cu `HORIZON_BASIC_AUTH_USER` sau `HORIZON_BASIC_AUTH_PASSWORD` necompletate, nu
 * trece nimeni (în afara mediului `local`, ca implicitul pachetului). Parola e în clar în env,
 * ca `DB_PASSWORD`: un hash bcrypt conține `$`, pe care interpolarea din compose și din Coolify
 * îl mănâncă în tăcere. Comparația trece prin `hash()` ca `hash_equals()` să primească șiruri de
 * aceeași lungime — nu scapă nici lungimea parolei prin timp.
 *
 * Încercările greșite (doar cele CU credențiale — prima cerere, fără antet, e doar provocarea
 * browserului) se numără per IP; peste prag, 429 până expiră fereastra. `HORIZON_PATH`
 * neghicibil (SEC-04) e al doilea strat, nu un substitut.
 *
 * Tot aici se generează nonce-ul CSP: Horizon își injectează JS-ul ca `<script type="module">`
 * inline, pe care `script-src 'self'` din `SecurityHeaders` l-ar bloca — dashboard gol.
 * `SecurityHeaders` adaugă nonce-ul la `script-src` doar când cererea curentă l-a generat.
 */
class HorizonBasicAuth
{
    public const MAX_FAILED_ATTEMPTS = 10;

    public const LOCKOUT_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'horizon-basic-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
            return response('', Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }

        if (! self::passes($request)) {
            if ($request->getUser() !== null) {
                RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            }

            return response('', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Horizon", charset="UTF-8"',
            ]);
        }

        Horizon::cspNonce(Vite::useCspNonce());

        return $next($request);
    }

    /**
     * Folosit și de `HorizonServiceProvider` pentru `Horizon::auth()`: gate-ul pachetului
     * (middleware-ul `Laravel\Horizon\Http\Middleware\Authenticate`) rulează după acesta și
     * trebuie să ia aceeași decizie.
     */
    public static function passes(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $user = (string) config('horizon.basic_auth.user');
        $password = (string) config('horizon.basic_auth.password');

        if ($user === '' || $password === '') {
            return false;
        }

        $userMatches = hash_equals(hash('sha256', $user), hash('sha256', (string) $request->getUser()));
        $passwordMatches = hash_equals(hash('sha256', $password), hash('sha256', (string) $request->getPassword()));

        return $userMatches && $passwordMatches;
    }
}
