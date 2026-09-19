<?php

// Settings (shell + Preferences) — plan §7.4, FR-PREF-01. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Settings\MembersController;
use App\Http\Controllers\Web\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('/settings/preferences', [SettingsController::class, 'preferences'])->name('settings.preferences');

// §6.4/§6.4.1 — US-TEN-02/03. `EnsureDemoModeGuardrails` (global, `bootstrap/app.php`)
// oprește `settings.members.deactivate` cât timp DEMO_MODE=true — vezi `App\Support\DemoMode`.
Route::get('/settings/members', [MembersController::class, 'index'])->name('settings.members.index');
Route::post('/settings/members/{membership}/deactivate', [MembersController::class, 'deactivate'])->name('settings.members.deactivate');
