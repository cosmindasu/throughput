<?php

// API public v1 (specs.md §18, ADR-008, plan §11, lotul F).
// Fără segment de workspace în cale (§18.2) — tenantul se rezolvă din jeton, server-side.
// Prefixul `api` e aplicat de `withRouting(api: …)` din bootstrap/app.php; `v1` e pus aici.

use App\Http\Controllers\Api\DocumentationController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Middleware\EnsureApiSubscriptionAccess;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\ResolveTenantFromApiToken;
use App\Http\Middleware\ThrottleApiToken;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Contractul publicat (FR-API-04)
|--------------------------------------------------------------------------
|
| Public, fără jeton: un contract care cere deja credențiale ca să fie citit nu
| îndeplinește scopul din §18 („cineva din afară poate să-l citească și să-l integreze
| fără să întrebe"). `throttle` clasic, cheiat pe IP — nu există jeton de cheiat aici.
|
*/
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('api.documentation');
    Route::get('/openapi.yaml', [DocumentationController::class, 'spec'])->name('api.documentation.spec');
});

/*
|--------------------------------------------------------------------------
| v1 — ADR-008: versionare PE CALE
|--------------------------------------------------------------------------
|
| Ordinea celor trei middleware globale ale grupului e semnificativă, la fel ca ordinea
| `session.context → workspace` de pe web (ADR-014, pct. 3), dar din alt motiv:
| `ThrottleApiToken` cheie pe `sha256` peste antet, fără nicio interogare, deci o cerere
| respinsă de limitator nu ajunge să deschidă tranzacția lui `ResolveTenantFromApiToken`.
| Inversate, limita ar fi apărat exact resursa pe care o consuma.
| `EnsureApiSubscriptionAccess` vine ultimul, fiindcă are nevoie de `app('tenant')` legat
| (specs.md §12.2 — degradarea pe 3 trepte se aplică și aici, nu doar pe web).
|
| Middleware-urile sunt referite prin numele CLASEI, nu prin alias: aliasurile se
| înregistrează în `bootstrap/app.php`, fișier comun. `->middleware([Foo::class])`
| funcționează identic și ține tot lotul într-un singur loc.
|
| PARAMETRII DE RUTĂ SUNT `string`, DELIBERAT. Grupul `api` al framework-ului conține
| `SubstituteBindings`, iar lista de prioritate din `bootstrap/app.php` ridică deasupra lui
| doar middleware-ul WEB de context. Un parametru tipizat (`show(Order $order)`) s-ar
| rezolva deci printr-o interogare Eloquent fără tenant și ar da 500
| (`TenantContextMissingException`) în loc de 404 — capcana descrisă în
| `.ai/rules/tenancy.md`. Fiecare controller își încarcă modelul prin
| `ApiController::findForTenant()`, ceea ce e oricum ce cere §18.5.
|
*/
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([ThrottleApiToken::class, ResolveTenantFromApiToken::class, EnsureApiSubscriptionAccess::class])
    ->group(function () {
        // --- Accounts (doar citire) -------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_ACCOUNTS_READ)->group(function () {
            Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        });

        // --- Contacts ---------------------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_CONTACTS_READ)->group(function () {
            Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
            Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        });

        Route::post('/contacts', [ContactController::class, 'store'])
            ->middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_CONTACTS_WRITE)
            ->name('contacts.store');

        // --- Deals ------------------------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_DEALS_READ)->group(function () {
            Route::get('/deals', [DealController::class, 'index'])->name('deals.index');
            Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
        });

        Route::post('/deals', [DealController::class, 'store'])
            ->middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_DEALS_WRITE)
            ->name('deals.store');

        // --- Orders -----------------------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_ORDERS_READ)->group(function () {
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        });

        // FR-API-03 / §18.4 — `Idempotency-Key` obligatoriu. `RequireIdempotencyKey` stă
        // DUPĂ `EnsureTokenAbility`: un jeton fără scop nu trebuie să poată revendica o
        // cheie de idempotență (ar fi putut bloca, fără niciun drept, cheia unui client
        // legitim până la expirarea celor 24h).
        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware([
                EnsureTokenAbility::class.':'.ApiToken::ABILITY_ORDERS_WRITE,
                RequireIdempotencyKey::class,
            ])
            ->name('orders.store');

        // --- Invoices ---------------------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_INVOICES_READ)->group(function () {
            Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        });

        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->middleware([
                EnsureTokenAbility::class.':'.ApiToken::ABILITY_INVOICES_WRITE,
                RequireIdempotencyKey::class,
            ])
            ->name('invoices.store');

        // --- Stock ------------------------------------------------------------------
        Route::middleware(EnsureTokenAbility::class.':'.ApiToken::ABILITY_INVENTORY_READ)->group(function () {
            Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('/stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
        });

        Route::post('/stock-movements', [StockMovementController::class, 'store'])
            ->middleware([
                EnsureTokenAbility::class.':'.ApiToken::ABILITY_INVENTORY_WRITE,
                RequireIdempotencyKey::class,
            ])
            ->name('stock-movements.store');
    });
