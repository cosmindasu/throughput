<?php

use App\Http\Controllers\Web\Contacts\ContactController;
use Illuminate\Support\Facades\Route;

// Contacte — FR-CRM-02, US-CRM-01. Inclus din routes/web.php, în grupul cu workspace.

// ÎNAINTEA resursei: `contacts/{contact}` ar prinde altfel „export" ca id de contact.
Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');

Route::resource('contacts', ContactController::class);
