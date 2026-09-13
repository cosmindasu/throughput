<?php

// Conturi — FR-CRM-01…04, US-CRM-01…03. Inclus din routes/web.php, în grupul cu workspace.

use App\Http\Controllers\Web\Accounts\AccountController;
use Illuminate\Support\Facades\Route;

// Declarată ÎNAINTEA resursei: Laravel potrivește rutele în ordinea înregistrării, iar
// `accounts/{account}` ar câștiga primul și ar căuta un cont cu id-ul literal „export".
Route::get('/accounts/export', [AccountController::class, 'export'])->name('accounts.export');

Route::resource('accounts', AccountController::class);
