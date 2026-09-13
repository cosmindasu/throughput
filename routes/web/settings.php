<?php

// Settings (shell + Preferences) — plan §7.4, FR-PREF-01. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('/settings/preferences', [SettingsController::class, 'preferences'])->name('settings.preferences');
