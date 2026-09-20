<?php

// Preferințele de temă și de limbă — FR-PREF-01…03, ADR-022/FR-I18N-01. Inclus din
// routes/web.php, autentificat, FĂRĂ workspace.

use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\ThemeController;
use Illuminate\Support\Facades\Route;

Route::patch('/preferences/theme', [ThemeController::class, 'update'])->name('preferences.theme');

Route::patch('/preferences/locale', [LocaleController::class, 'update'])->name('preferences.locale');
