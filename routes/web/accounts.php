<?php

// Conturi — FR-CRM-01…04, US-CRM-01…03. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Accounts\AccountController;
use App\Http\Controllers\Web\Accounts\AccountLookupController;
use Illuminate\Support\Facades\Route;

// Declarate ÎNAINTEA resursei: Laravel potrivește rutele în ordinea înregistrării, iar
// `accounts/{account}` ar câștiga primul și ar căuta un cont cu id-ul literal „export"
// sau „lookup".
Route::get('/accounts/export', [AccountController::class, 'export'])->name('accounts.export');

// P2-001 (code review pachetul „contacte") — endpoint JSON pentru `AccountCombobox`.
Route::get('/accounts/lookup', AccountLookupController::class)->name('accounts.lookup');

Route::resource('accounts', AccountController::class);
