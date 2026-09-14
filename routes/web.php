<?php

use App\Http\Controllers\Web\Auth\DemoLoginController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\NewPasswordController;
use App\Http\Controllers\Web\Auth\PasswordResetLinkController;
use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

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

// Autentificate, fără workspace încă rezolvat (ADR-014: auth → session.context → workspace,
// ordinea contează — vezi comentariul din SetSessionContext).
Route::middleware(['auth', 'session.context'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'redirectToDefaultWorkspace'])->name('dashboard');

    // Preferințele sunt ale persoanei, nu ale organizației (FR-PREF-02, BR-HELP-02): fără
    // segment de workspace, deci neafectate de comutare.
    require __DIR__.'/web/preferences.php';
    require __DIR__.'/web/hints.php';

    // Cu workspace în cale (ADR-002).
    Route::middleware('workspace')->prefix('{workspace}')->group(function () {
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
    });
});
