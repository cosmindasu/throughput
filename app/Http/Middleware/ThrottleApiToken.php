<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-API-05 / specs.md §22.5 — 300 de cereri pe minut PER JETON, cu `Retry-After` la
 * depășire.
 *
 * Rulează ÎNAINTEA lui `ResolveTenantFromApiToken`, deliberat: cheia e `sha256` peste
 * jetonul brut din antet, deci nu are nevoie nici de o interogare, nici de contextul de
 * tenant, nici de tranzacția deschisă. Pus după rezolvare, fiecare cerere respinsă ar fi
 * costat totuși două SELECT-uri și o tranzacție Postgres pe un container cu
 * `max_connections=30` — adică limita ar fi apărat exact resursa pe care o consuma.
 *
 * Fără jeton în antet, cheia cade pe IP: altfel o rafală de cereri neautentificate ar
 * trece nelimitat prin limitator și s-ar opri abia la 401.
 */
class ThrottleApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('throughput.limits.api_rate_limit_per_minute');

        if ($limit <= 0) {
            return $next($request);
        }

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many requests. This token is limited to '.$limit.' requests per minute.',
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $response;
    }

    private function throttleKey(Request $request): string
    {
        $bearer = $request->bearerToken();

        return 'api-token:'.($bearer !== null && $bearer !== ''
            ? hash('sha256', $bearer)
            : 'ip:'.$request->ip());
    }
}
