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
 * `Permissions-Policy` NU e din specs §20.2 — e hardening adăugat peste cerință, în urma
 * auditului de securitate din 2026-09-23 (SEC-05). Absența lui nu era o încălcare de
 * cerință, doar o ocazie ratată.
 *
 * În aplicație, nu doar în nginx: așa sunt prezente pe orice cale de servire și verificate
 * de SecurityHeadersTest. nginx le repetă, cu ACELEAȘI valori, pentru fișierele statice pe
 * care PHP nu le vede niciodată — `Permissions-Policy` inclus (`docker/nginx/default.conf`).
 */
class SecurityHeaders
{
    /**
     * Politica pentru build-ul de producție — tot ce execută pagina vine din `self`.
     *
     * - `script-src 'self'`, fără nicio excepție STATICĂ: scripturile Vite sunt fișiere, iar
     *   pagina inițială Inertia e un `<script type="application/json">`, care nu se execută.
     *   Excepția DINAMICĂ (nonce) e descrisă la `contentSecurityPolicy()` mai jos — OPS-03.
     * - `style-src 'unsafe-inline'`: bara de progres Inertia injectează un `<style>` din JS,
     *   iar `<html>` poartă `style="color-scheme: …"` randat server-side (FR-PREF-03). Un
     *   stil inline nu execută cod; excepția nu se extinde la scripturi.
     * - `frame-ancestors 'none'`: echivalentul CSP al lui `X-Frame-Options: DENY`.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /**
     * SEC-05 — dezactivează explicit API-urile pe care aplicația nu le folosește, ca strat
     * suplimentar dacă vreodată un script terț reușește să ruleze în pagină (CSP e prima
     * linie de apărare; asta e a doua, pentru API-uri browser pe care CSP nu le acoperă).
     *
     * Verificat în `resources/js` (grep, nu presupus) înainte de a alege lista:
     *   - `geolocation`, `camera`, `microphone`, `usb`, `magnetometer`, `gyroscope`,
     *     `accelerometer`: zero utilizări — dezactivate peste tot (`()`).
     *   - `payment`: zero utilizări ale Payment Request API / `<iframe allow="payment">`.
     *     Abonamentul (`BillingController::portal()`) trimite browserul ÎNTREG spre Stripe
     *     Customer Portal cu `Inertia::location()` (navigare completă, alt domeniu), nu-l
     *     îmbrăcă într-un iframe/checkout embedded — deci `payment=()`, nu `payment=(self)`.
     *   - `clipboard-write`: FOLOSIT (`resources/js/Pages/Settings/ApiTokens/Index.tsx`,
     *     butonul de copiere a jetonului nou creat) — rămâne permis, dar restrâns la
     *     `(self)`, niciodată moștenit de un iframe cross-origin (n-avem niciunul cu `allow`).
     */
    public const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), clipboard-write=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // Cu serverul Vite de dezvoltare pornit, modulele vin de pe alt origin, iar HMR merge
        // prin WebSocket: politica strictă ar rupe exact mediul local. `public/hot` există
        // doar atunci, niciodată într-o imagine de producție.
        if (! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        // Doar pe HTTPS: pe HTTP browserele îl ignoră oricum (RFC 6797). Traefik termină
        // TLS-ul, iar `trustProxies(at: '*')` face ca cererea să se vadă sigură aici.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * OPS-03 — dashboard-ul Horizon își randează JS-ul ca
     * `<script type="module">` INLINE și CSS-ul ca `<style>` inline
     * (`vendor/laravel/horizon/src/Horizon.php::css()/js()`). Sub `script-src 'self'` static,
     * pagina ar rămâne goală în producție — CSP-ul ar bloca exact scriptul care randează
     * dashboard-ul.
     *
     * Mecanismul e GENERAL, nu special pentru Horizon: dacă cererea curentă a generat deja un
     * nonce prin `Vite::useCspNonce()` (verificat cu `Vite::cspNonce()`, care doar CITEȘTE),
     * `script-src` primește `'nonce-<nonce>'` în plus. Fără niciun apel la
     * `useCspNonce()` undeva mai devreme în cerere, `Vite::cspNonce()` întoarce `null`, iar
     * politica rămâne identică, caracter cu caracter, cu `CONTENT_SECURITY_POLICY` — nicio
     * pagină a aplicației (care nu cere nonce) nu se schimbă.
     *
     * Cine setează nonce-ul, azi: `App\Http\Middleware\HorizonBasicAuth`, înainte de
     * `$next($request)`. Citirea de-aici are loc DUPĂ `$next($request)`
     * de mai sus (linia care apelează această metodă), deci vede orice nonce generat oriunde
     * mai jos în pipeline — indiferent de poziția relativă a lui `HorizonBasicAuth` față de
     * `SecurityHeaders` în grupul de middleware al rutei.
     *
     * Doar `script-src` primește nonce-ul, NICIODATĂ `style-src`: cu un nonce prezent
     * într-o directivă CSP3, browserele ignoră `'unsafe-inline'` DOAR în ACEA directivă —
     * dacă am fi adăugat nonce-ul și la `style-src`, `'unsafe-inline'` de-acolo (necesar
     * barei de progres Inertia, FR-PREF-03) ar fi fost ignorat pe orice pagină cu nonce.
     * `<style>`-urile Horizon trec oricum prin `'unsafe-inline'`, care rămâne activ.
     *
     * ATENȚIE la reutilizare: `Illuminate\Foundation\Vite` e înregistrat ca `singleton`
     * simplu (`FoundationServiceProvider::$singletons`), NU `scoped`. Sub php-fpm (cum
     * rulează acest proiect — vezi docker/app/Dockerfile) aplicația și containerul ei de
     * servicii se reconstruiesc la fiecare cerere, deci nu există scurgere de nonce între
     * cereri REALE.
     * NU s-ar mai ține dacă proiectul ar adopta vreodată un runtime cu proces lung
     * (Octane) — regula de buget de memorie din `.ai/rules/project.md` exclude deja asta
     * pentru MVP, dar dacă se schimbă vreodată, acest binding trebuie revizitat la `scoped()`
     * cu golire explicită per cerere, altfel un nonce ar supraviețui request-ului care l-a
     * generat. În teste Pest/PHPUnit, aceeași instanță de container supraviețuiește între
     * MAI MULTE `$this->get()` din ACELAȘI test (nu se reconstruiește automat) — vezi ordinea
     * asertărilor din `SecurityHeadersTest::test_content_security_policy_gets_a_nonce_*`.
     */
    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();

        if ($nonce === null) {
            return self::CONTENT_SECURITY_POLICY;
        }

        return str_replace(
            "script-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            self::CONTENT_SECURITY_POLICY
        );
    }
}
