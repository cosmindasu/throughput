<?php

// Preferința de temă — FR-PREF-01…03. Inclus din routes/web.php, autentificat, FĂRĂ workspace.

use App\Http\Controllers\Web\ThemeController;
use Illuminate\Support\Facades\Route;

Route::patch('/preferences/theme', [ThemeController::class, 'update'])->name('preferences.theme');
