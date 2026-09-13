<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headerele HTTP obligatorii din specs.md §20.2 (checklist plan §15): CSP, HSTS,
 * X-Frame-Options, X-Content-Type-Options, Referrer-Policy. `X-Robots-Tag` rămâne al lui
 * NoIndexHeaders (FR-PUB-04).
 *
 * În aplicație, nu doar în nginx: așa sunt prezente pe orice cale de servire și verificate
 * de SecurityHeadersTest. nginx le repetă, cu ACELEAȘI valori, pentru fișierele statice pe
 * care PHP nu le vede niciodată.
 */
class SecurityHeaders
{
    /**
     * Politica pentru build-ul de producție — tot ce execută pagina vine din `self`.
     *
     * - `script-src 'self'`, fără nicio excepție: scripturile Vite sunt fișiere, iar pagina
     *   inițială Inertia e un `<script type="application/json">`, care nu se execută.
     * - `style-src 'unsafe-inline'`: bara de progres Inertia injectează un `<style>` din JS,
     *   iar `<html>` poartă `style="color-scheme: …"` randat server-side (FR-PREF-03). Un
     *   stil inline nu execută cod; excepția nu se extinde la scripturi.
     * - `frame-ancestors 'none'`: echivalentul CSP al lui `X-Frame-Options: DENY`.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // Cu serverul Vite de dezvoltare pornit, modulele vin de pe alt origin, iar HMR merge
        // prin WebSocket: politica strictă ar rupe exact mediul local. `public/hot` există
        // doar atunci, niciodată într-o imagine de producție.
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Doar pe HTTPS: pe HTTP browserele îl ignoră oricum (RFC 6797). Traefik termină
        // TLS-ul, iar `trustProxies(at: '*')` face ca cererea să se vadă sigură aici.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
