<?php

/**
 * SEC-03, audit de securitate 2026-09-23 — `config/cors.php` explicit.
 *
 * `Illuminate\Http\Middleware\HandleCors` e middleware GLOBAL (înregistrat implicit de
 * framework pentru toate cererile, vezi
 * `Illuminate\Foundation\Configuration\Middleware::$global`), care intervine DOAR pe căile
 * din `cors.paths` — inclusiv preflight-ul `OPTIONS`, înainte ca ruta să se rezolve. De-aici
 * testul poate lovi un `OPTIONS` real, fără niciun mock al pachetului CORS.
 *
 * Cele două invariante verificate corespund exact comentariilor din `config/cors.php`:
 *   1. API-ul public v1 răspunde la orice origine (`Authorization: Bearer`, fără cookie),
 *      dar NICIODATĂ cu `Access-Control-Allow-Credentials` — combinația cu `origins: '*'`
 *      ar fi interzisă de spec-ul Fetch oricum, dar testul o fixează ca regresie.
 *   2. O rută `web` (sesiune de cookie) nu primește NICIUN header CORS, indiferent de
 *      `Origin` — `web` nu e în `cors.paths`.
 */
it('answers a cross-origin preflight on the public API with a wildcard, credential-less origin', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://third-party.example.test',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/v1/accounts');

    $response->assertStatus(204);
    $response->assertHeader('Access-Control-Allow-Origin', '*');
    $response->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('exposes the rate-limit and idempotency headers to a cross-origin API client', function () {
    // `Access-Control-Expose-Headers` se atașează doar pe cererea REALĂ
    // (`CorsService::addActualRequestHeaders()`), nu pe preflight — de-aici un GET simplu,
    // nu un OPTIONS. Statusul răspunsului (401, fără jeton) nu contează: `HandleCors`
    // adaugă headerul indiferent de statusul întors de `$next($request)`.
    $response = $this->withHeaders([
        'Origin' => 'https://third-party.example.test',
    ])->get('/api/v1/accounts');

    $exposed = (string) $response->headers->get('Access-Control-Expose-Headers');

    expect($exposed)
        ->toContain('X-RateLimit-Limit')
        ->toContain('X-RateLimit-Remaining')
        ->toContain('Retry-After')
        ->toContain('Idempotent-Replay');
});

it('does not attach any CORS header to a web route, even with a foreign Origin', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://third-party.example.test',
    ])->get('/login');

    $response->assertOk();
    $response->assertHeaderMissing('Access-Control-Allow-Origin');
    $response->assertHeaderMissing('Access-Control-Allow-Credentials');
});
