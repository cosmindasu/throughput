<?php

use App\Http\Controllers\Web\Auth\DemoLoginController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\NewPasswordController;
use App\Http\Controllers\Web\Auth\PasswordResetLinkController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// specs.md §12.3 — webhook Stripe: PUBLIC, fără sesiune/workspace, exceptat de CSRF
// (`bootstrap/app.php`). `Cashier::ignoreRoutes()` (AppServiceProvider) a dezactivat ruta
// implicită a pachetului — handler propriu, cu idempotență pe `webhook_events` (ADR-006).
//
// P2 securitate (review-ul lotului, pct. 4) — `throttle:60,1`, cheiat implicit pe IP
// (`ThrottleRequests`: `$request->user()?->id ?: $request->ip()`, fără utilizator aici).
// Cifra: Stripe reîncearcă un webhook nelivrat cu backoff exponențial, niciodată mai des
// de câteva ori/minut per endpoint în practică — 60/min per IP e generos față de ritmul
// real, dar tot mărginește o rafală de cereri mari, nesemnate, care altfel s-ar citi
// integral și s-ar hash-ui (`hash('sha256', $payload)`) înainte de a fi respinse, pe un
// pool de 4 workeri PHP-FPM partajați cu toată aplicația. Limita de body (32 MB nginx) e
// infrastructură, nu cod — o fac separat.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.stripe');

// Publice — plan §7.4.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    // FR-PUB-02, BR-PUB-01 — `{role}` restricționat la nivel de rută; orice altă
    // valoare e 404 înainte să ajungă la controller. Comportamentul DEMO_MODE=false
    // (tot 404) se verifică în controller (citește config, nu env()).
    Route::post('/login/demo/{role}', [DemoLoginController::class, 'store'])
        ->whereIn('role', ['owner', 'manager', 'agent', 'viewer'])
        ->name('login.demo');

    // FR-PUB-05, specs §4.5.
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// §6.4, US-TEN-01 (valul 2 al Fazei 5) — acceptarea unei invitații. PUBLICĂ, în afara
// grupului cu `{workspace}`: invitatul n-are încă membership activ, deci `ResolveWorkspace`
// i-ar da 404 pe propriul link. Și în afara grupului `guest`: poate fi deja autentificat în
// altă organizație, iar `guest` l-ar redirecta spre dashboard-ul lui, nu spre invitație.
require __DIR__.'/web/invitations.php';

// Autentificate, fără workspace încă rezolvat (ADR-014: auth → session.context → workspace,
// ordinea contează — vezi comentariul din SetSessionContext).
Route::middleware(['auth', 'session.context'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'redirectToDefaultWorkspace'])->name('dashboard');

    // Preferințele sunt ale persoanei, nu ale organizației (FR-PREF-02, BR-HELP-02): fără
    // segment de workspace, deci neafectate de comutare.
    require __DIR__.'/web/preferences.php';
    require __DIR__.'/web/hints.php';

    // Cu workspace în cale (ADR-002). `subscription.access` (Faza 5, plan §11, specs.md
    // §12.2) DUPĂ `workspace`, ca `app('tenant')`/`URL::defaults` să fie deja disponibile —
    // decide după METODA cererii (safe = citire, restul = scriere), nu după o listă de
    // rute întreținută manual: modulele Faza 2-4 nu sunt fișierele acestui lot.
    Route::middleware(['workspace', 'subscription.access'])->prefix('{workspace}')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('workspace.dashboard');

        // Faza 2: un fișier per modul. Grupul (auth → session.context → workspace) și
        // prefixul se moștenesc de aici, deci niciun fișier de modul nu poate ajunge pe
        // grupul greșit — regresia pe care MiddlewareOrderTest o urmărește.
        require __DIR__.'/web/accounts.php';
        require __DIR__.'/web/contacts.php';
        require __DIR__.'/web/deals.php';
        require __DIR__.'/web/pipeline.php';
        require __DIR__.'/web/saved-views.php';
        require __DIR__.'/web/search.php';
        require __DIR__.'/web/settings.php';
        require __DIR__.'/web/exports.php';
        // Pachetul A (Faza 3, specs.md §10) — catalog de produse și stoc.
        require __DIR__.'/web/products.php';
        require __DIR__.'/web/stock.php';
        // Comenzi și mașină de stări (Faza 3, specs.md §11, plan §9).
        require __DIR__.'/web/orders.php';
        // Pachetul C (Faza 3, specs.md §13, plan §9) — mecanismul generic de operații în masă.
        require __DIR__.'/web/bulk.php';
        // US-TEN-03, §6.4.1 — vederea „Unassigned" (FR-TEN-05).
        require __DIR__.'/web/unassigned.php';
        // Faza 4 (specs.md §14, plan §10) — importul CSV în 4 pași.
        require __DIR__.'/web/imports.php';
        // Faza 4 (specs.md §16, plan §10) — rapoarte și livrare programată.
        require __DIR__.'/web/reports.php';
        // Faza 5 (specs.md §12.1, plan §11) — facturare către clienți, AR intern (ADR-005).
        require __DIR__.'/web/invoices.php';
        // Faza 5 (specs.md §12.2, plan §11) — abonamentul Throughput (Cashier), Owner-only.
        require __DIR__.'/web/billing.php';
        // Faza 5 (specs.md §17, plan §11, lotul E) — jurnal de activitate.
        require __DIR__.'/web/activity.php';
        // Faza 5, valul 2 (specs.md §18, plan §11, lotul F) — ecranul de jetoane API.
        // Doar ADMINISTRAREA jetoanelor e o rută web; API-ul public însuși n-are segment
        // de workspace în cale (§18.2) și trăiește în routes/api.php.
        require __DIR__.'/web/api-tokens.php';
        // Faza 5, valul 2 (specs.md §20.5, plan §11, lotul G) — export de date GDPR.
        require __DIR__.'/web/data-export.php';
    });
});
