<?php

use App\Http\Controllers\Web\Contacts\ContactController;
use Illuminate\Support\Facades\Route;

// Contacte — FR-CRM-02, US-CRM-01. Inclus din routes/web.php, în grupul cu workspace.
Route::resource('contacts', ContactController::class);
