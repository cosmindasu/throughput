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

    // Cu workspace în cale (ADR-002).
    Route::middleware('workspace')->prefix('{workspace}')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('workspace.dashboard');
    });
});
