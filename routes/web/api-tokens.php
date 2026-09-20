<?php

// Jetoane API — ecranul de ADMINISTRARE (specs.md §18, FR-API-01/02, plan §11, lotul F).
// Inclus din routes/web.php, în grupul cu workspace. API-ul public însuși e în routes/api.php
// și NU are segment de workspace în cale (§18.2) — tenantul se rezolvă din jeton, server-side.

use Illuminate\Support\Facades\Route;

Route::get('/settings/api-tokens', [\App\Http\Controllers\Web\Settings\ApiTokenController::class, 'index'])->name('settings.api-tokens.index');
Route::post('/settings/api-tokens', [\App\Http\Controllers\Web\Settings\ApiTokenController::class, 'store'])->name('settings.api-tokens.store');
Route::delete('/settings/api-tokens/{apiToken}', [\App\Http\Controllers\Web\Settings\ApiTokenController::class, 'destroy'])->name('settings.api-tokens.destroy');
