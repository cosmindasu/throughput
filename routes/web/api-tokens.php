<?php

// Jetoane API — ecranul de ADMINISTRARE (specs.md §18, FR-API-01/02, plan §11, lotul F).
// Inclus din routes/web.php, în grupul cu workspace. API-ul public însuși e în routes/api.php
// și NU are segment de workspace în cale (§18.2) — tenantul se rezolvă din jeton, server-side.

use App\Http\Controllers\Web\Settings\ApiTokenController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/api-tokens', [ApiTokenController::class, 'index'])->name('settings.api-tokens.index');
Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->name('settings.api-tokens.store');
// §22.2, „Revocarea în masă a tuturor jetoanelor API" — decizia proprietarului (2026-09-22):
// butonul se construiește. Numele e UNUL dintre cele două fixate deja în
// `App\Support\DemoMode::GUARDED_ACTIONS['api-tokens.revoke-all']`, ca guardrail-ul să
// prindă acțiunea fără nicio modificare acolo; `settings.api-tokens.revoke-all` rămâne
// nefolosit ca variantă (vezi raportul lotului).
Route::delete('/settings/api-tokens', [ApiTokenController::class, 'destroyAll'])->name('settings.api-tokens.destroy-all');
Route::delete('/settings/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('settings.api-tokens.destroy');
