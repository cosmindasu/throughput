<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) — SEC-03, audit 2026-09-23
    |--------------------------------------------------------------------------
    |
    | Fișier absent înainte de acest audit: `/api/*` moștenea TĂCUT implicitul din
    | `vendor/laravel/framework/.../config/cors.php` — întâmplător identic cu ce e publicat
    | aici, dar niciodată o decizie scrisă, deci nimeni n-ar fi observat dacă un `composer
    | update` viitor schimba implicitul pachetului. Publicat acum ca decizie EXPLICITĂ,
    | auditabilă în PR-uri viitoare.
    |
    | INVARIANTE care fac `allowed_origins: ['*']` sigur AICI (NU generalizabil la orice
    | rută nouă — verifică-le din nou dacă adaugi ceva sub `api/*`):
    |
    |   1. API-ul public v1 (`routes/api.php`, ADR-008) se autentifică EXCLUSIV prin
    |      `Authorization: Bearer <token>` (jetoane personale Sanctum, `App\Models\ApiToken`)
    |      — niciodată prin cookie de sesiune. Un JS de pe alt domeniu care citește un
    |      răspuns cross-origin nu poartă cookie-ul de sesiune Laravel către noi, deci nu
    |      există scenariul clasic „CORS + cookie" (CSRF prin fetch cross-site).
    |   2. `supports_credentials: false` mai jos — NU se schimbă NICIODATĂ la `true` cât timp
    |      `allowed_origins` rămâne `['*']`. Combinația e interzisă chiar de spec-ul Fetch
    |      (browserul o respinge la runtime), dar dacă cineva „repară" asta înlocuind `*` cu
    |      o listă explicită de origini ȘI pornește `supports_credentials: true` fără să
    |      recitească acest comentariu, invarianta (1) tot s-ar rupe pentru orice rută care
    |      ar începe să citească sesiunea.
    |   3. Nicio rută `api/*` nu citește sesiunea Sanctum „stateful": SPA-ul Inertia e
    |      same-origin și se autentifică direct pe guard-ul `web` (sesiune), nu prin Sanctum.
    |      `EnsureFrontendRequestsAreStateful` nu e înregistrat nicăieri în proiect și
    |      `config/sanctum.php` nu e publicat (verificat — zero rezultate pentru
    |      `stateful`/`EnsureFrontendRequestsAreStateful` în `app/`, `config/`, `bootstrap/`,
    |      `routes/`). Dacă asta se schimbă vreodată, ruta stateful NU trebuie să stea sub
    |      `api/*` cu acest fișier neschimbat.
    |
    | Consecință directă a lui (1)+(3): `sanctum/csrf-cookie` a fost SCOS din `paths` de mai
    | jos — ruta există (Sanctum o înregistrează singur), dar aplicația n-o folosește:
    | niciun apel din `resources/js`, niciun `SANCTUM_STATEFUL_DOMAINS` configurat. A o lăsa
    | acolo era exact genul de „cale CORS deschisă fără motiv" pe care SEC-03 cere s-o
    | elimine, nu doar s-o documenteze.
    |
    */

    'paths' => ['api/*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // Doar verbele efectiv rutate în routes/api.php la data acestei decizii — toate rutele
    // v1 sunt GET (citire) sau POST (scriere/creare), nicio PUT/PATCH/DELETE. `OPTIONS`
    // (preflight) e gestionat de `HandleCors` indiferent de listă. Dacă o rută nouă adaugă
    // un verb absent de-aici, actualizează și lista: CORS nu deschide nimic ce routingul
    // n-ar accepta oricum, dar lista explicită e documentația vie a suprafeței publice.
    'allowed_methods' => ['GET', 'POST'],

    // Ce trimite efectiv un client al API-ului public, conform openapi/throughput-v1.yaml:
    // `Authorization` (Bearer), `Content-Type` (JSON pe scrieri), `Accept`, și
    // `Idempotency-Key` (FR-API-03, doar pe POST-urile de creare). `Accept-Language`
    // deliberat absent din listă: API-ul nu localizează după antet — interzis explicit de
    // FR-I18N-01 (vezi App\Support\LocalePreference, care ignoră headerul chiar și pe web).
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key'],

    // Ce mai citește clientul înapoi, dincolo de headerele „safelisted" pe care CORS le
    // expune implicit (Content-Type, Content-Length etc.): rate limiting per-jeton
    // (FR-API-05, App\Http\Middleware\ThrottleApiToken) și semnalul de reluare idempotentă
    // (FR-API-03, App\Http\Middleware\RequireIdempotencyKey). Niciunul din cele patru e
    // safelisted de Fetch — fără `exposed_headers`, un client de pe alt domeniu le-ar primi
    // pe rețea, dar `fetch()`/`XMLHttpRequest` din JS nu le-ar putea citi.
    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'Idempotent-Replay'],

    'max_age' => 0,

    // NICIODATĂ `true` cât `allowed_origins` rămâne `['*']` — vezi invarianta (2) de mai sus.
    'supports_credentials' => false,

];
