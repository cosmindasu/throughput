<?php

use App\Http\Middleware\EnsureDemoModeGuardrails;
use App\Http\Middleware\EnsureSubscriptionAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexHeaders;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetSessionContext;
use App\Support\ErrorPageStatus;
use App\Support\LocalePreference;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    // BUG DE FUNDAȚIE găsit la testarea lotului de abonament (Faza 5, specs.md §12.2),
    // reparat aici — corectare, nu presupunere.
    //
    // `Illuminate\Foundation\Application::configure()` cheamă intern `->withEvents()` CU
    // discovery activat implicit, ÎNAINTE ca acest fișier să apuce să configureze ceva —
    // fără nicio linie explicită aici care s-o ceară. Efectul: ORICE listener din
    // `app/Listeners/**` cu `handle()` tipizat pe o clasă de eveniment se înregistrează
    // SINGUR, prin descoperire automată — și, dacă ACEEAȘI pereche eveniment→listener e
    // ÎNREGISTRATĂ ȘI EXPLICIT (`Event::listen(...)`, cum face acest lot în
    // `AppServiceProvider::register()` și lotul de jurnal de activitate în
    // `ActivityLogServiceProvider::boot()`), evenimentul se execută DE DOUĂ ORI la fiecare
    // `event()` — reprodus direct: un singur `event(new SubscriptionBecameUnpaid(...))`
    // punea DOUĂ joburi `SendSubscriptionUnpaidEmail` în coadă, deci DOUĂ emailuri pentru
    // O SINGURĂ tranziție (specs.md §12.2 cere explicit „o singură dată"). Explică și
    // numărătorile duble văzute în suita altui lot, în lucru concurent (`ActivityLogObserverTest`,
    // „size 6 vs 3"; `QueuedJobContextTest`, „6 identic cu 2") — ACELAȘI mecanism,
    // `WriteActivityLogEntry` fiind și el înregistrat explicit ȘI descoperit automat.
    //
    // `discover: false` dezactivează descoperirea automată global — fiecare pereche
    // eveniment→listener din proiect e ORICUM înregistrată explicit (`Event::listen()`),
    // deci nimic nu se pierde; se elimină doar dubla înregistrare tăcută.
    ->withEvents(discover: false)
    // OPS-04 (audit infra 2026-09-23) — `/up` (parametrul `health` de mai jos) verifica doar
    // că PHP a bootat: cu Postgres sau Redis căzute, dar php-fpm în viață, tot răspundea 200.
    // Ruta face `Event::dispatch(new DiagnosingHealth)` într-un `try/catch` și răspunde 500
    // dacă un listener aruncă (`ApplicationBuilder::buildRoutingCallback()`), deci ajunge un
    // listener care atinge ambele conexiuni. Legat aici, explicit (descoperirea automată e
    // oprită mai sus): e un callback fără stare al rutei de sănătate, nu un eveniment de domeniu.
    //
    // Doar healthcheck-ul lui `nginx` (docker-compose.coolify.yml) cheamă `/up`; al lui `app`
    // verifică doar că FPM ascultă pe 9000. Deliberat: o dependență căzută scoate site-ul din
    // rotația Traefik (utilizatorii ar primi oricum 500), dar nu declară mort procesul PHP,
    // care își revine singur când Redis/Postgres revin. 30 s × 3 încercări pe `nginx`
    // absorb o întrerupere scurtă fără să scoată site-ul din rotație.
    ->booted(function (): void {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->getPdo();
            Redis::connection()->ping();
        });
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API public v1 (specs.md §18, ADR-008, plan §11, valul 2 al Fazei 5). Prefixul
        // implicit `api` plus versiunea pe cale, DELIBERAT fără segment de workspace
        // (§18.2): tenantul se rezolvă din jeton, server-side, niciodată din URL.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // ADR-022, specs.md §15.8 FR-I18N-01 — PRIMUL din listă, deliberat: fixează
            // `App::setLocale()` înaintea a tot ce urmează, inclusiv `HandleInertiaRequests`
            // (propul `locale`) de mai jos. Global pe `web`, nu în spatele lui `auth`: are
            // nevoie doar de `$request->user()` din sesiune (vezi comentariul din
            // App\Http\Middleware\SetLocale), la fel ca ThemePreference.
            SetLocale::class,

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

        // FR-PUB-04 pe grupul `api`, nu doar pe `web`. `NoIndexTest` cere antetul pe
        // FIECARE rută înregistrată, iar valul 2 a adăugat prima familie de rute care nu
        // trece prin `web` — inclusiv `/api/documentation`, o pagină HTML publică, exact
        // ce ar indexa un crawler. Restul răspund JSON, unde antetul nu strică nimic.
        // Doar `NoIndexHeaders`: celelalte din lista `web` de mai sus sunt legate de
        // sesiune, de Inertia sau de CSP, niciuna cu sens pe un client de API, iar
        // contextul de tenant al API-ului îl rezolvă propriul middleware din `routes/api.php`
        // (ADR-014 — jetonul e sursa, nu sesiunea).
        $middleware->api(append: [
            NoIndexHeaders::class,
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
            // specs.md §12.2, plan §11 — modelul de degradare pe 3 trepte al abonamentului
            // Throughput. Aplicat explicit pe grupul cu workspace, în routes/web.php.
            'subscription.access' => EnsureSubscriptionAccess::class,
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
        // strica randarea fără licărire. `locale` e exceptat identic,
        // același motiv (ADR-022, FR-I18N-01): comutatorul de limbă din
        // Settings → Preferences scrie cookie-ul din JS.
        $middleware->encryptCookies(except: ['theme', 'locale']);

        // specs.md §12.3 — Stripe nu trimite (și n-are cum să obțină) un token CSRF
        // Laravel; semnătura `Stripe-Signature` (verificată în
        // `App\Http\Controllers\Webhooks\StripeWebhookController`) e mecanismul de
        // încredere al acestei rute, nu sesiunea.
        $middleware->validateCsrfTokens(except: ['webhooks/stripe']);

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

        // ADR-022, specs.md §15.8 FR-I18N-04 — limba pentru VEDERILE DE EROARE ale
        // framework-ului (`lang/{en,fr}.json`: „Not Found", „Page Expired", …).
        //
        // `SetLocale` stă în grupul `web`, deci NU acoperă tot ce ajunge aici. Măsurat, nu
        // presupus: un 404 pe o rută inexistentă e aruncat de router ÎNAINTE ca grupul `web`
        // să se aplice, iar un 419 e aruncat de `ValidateCsrfToken`, care rulează înaintea
        // lui `SetLocale` (acesta e `append`-uit, deci ultimul din grup). Ambele randau
        // engleză pentru un utilizator francez — cu catalogul tradus lângă ele, nefolosit.
        //
        // Callback-ul întoarce `null` DELIBERAT: fixează doar limba și lasă randarea
        // implicită să continue (`renderViaCallbacks()` trece mai departe la `null`). Nu e
        // un handler de erori, e un pas de locale.
        //
        // Pe calea asta `$request->user()` e adesea `null` (sesiunea nu a pornit), deci
        // rezoluția cade pe pasul 2 din `LocalePreference` — cookie-ul `locale`, exceptat de
        // la criptare tocmai ca să fie lizibil fără grupul `web`. Degradare acceptată și
        // identică cu a temei: cine a comutat limba o dată are cookie-ul. Unde sesiunea A
        // pornit (403 dintr-un Policy), pasul 1 câștigă ca oriunde altundeva.
        $exceptions->render(function (Throwable $e, Request $request) {
            App::setLocale(LocalePreference::resolveForRequest($request));

            return null;
        });

        // ADR-024 — pe o cerere Inertia, vederile din `resources/views/errors/` ajung la
        // client ca `text/html` FĂRĂ antetul `X-Inertia` (verificat pe live: 404 → 2914
        // octeți de Blade, fără antet). `@inertiajs/react` nu le poate trata ca navigare,
        // deci le afișează într-un modal — o pagină întreagă, albă, peste o aplicație
        // închisă la culoare: vizitatorul vede un dreptunghi gol, fără mesaj.
        //
        // Doar cererile Inertia primesc pagina din aplicație. Restul (inclusiv o încărcare
        // completă de pagină) păstrează vederile Blade NEATINSE — ele sunt singurele care se
        // randează și când build-ul frontend lipsește, fiindcă nu folosesc `@vite(...)`
        // (`resources/views/errors/layout.blade.php`). O cerere Inertia nu poate exista fără
        // build, deci garanția aia nu se pierde nicăieri.
        //
        // `api/*` și orice `expectsJson()` sunt deja rutate spre JSON de
        // `shouldRenderJsonWhen()` mai sus, care rulează înaintea acestui callback.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return $response;
            }

            $status = $response->getStatusCode();

            // Exact statusurile care au vedere Blade proprie. Orice altceva trece mai
            // departe neatins, în loc să fie înghițit de o pagină generică.
            if (! ErrorPageStatus::supports($status)) {
                return $response;
            }

            // Textele vin rezolvate de pe server, din ACELEAȘI chei `lang/{en,fr}.json` pe
            // care le folosesc vederile Blade — pagina Inertia n-are catalog propriu, deci
            // cele două randări nu pot diverge. Limba e deja fixată de pasul de locale de
            // mai sus (ADR-022), care rulează înaintea acestui callback.
            //
            // `try` NU e prudență decorativă: `HandleInertiaRequests::share()` expune
            // `auth.user`, `workspaces`, `navigation` și `subscription` ca închideri care
            // INTEROGHEAZĂ baza, iar o randare completă (nu parțială) le rezolvă pe toate.
            // Exact pe cauza cea mai probabilă a unui 500 — baza indisponibilă — pagina asta
            // ar arunca a doua oară, din interiorul handler-ului de erori. Atunci cedăm locul
            // vederii Blade, care nu depinde de nimic: un 404 corect afișat într-un modal e
            // mai bun decât o excepție în timpul tratării unei excepții.
            try {
                return Inertia::render('Error', [
                    'status' => $status,
                    ...ErrorPageStatus::copyFor($status, $e->getMessage()),
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (Throwable) {
                return $response;
            }
        });
    })->create();
