<?php

use App\Http\Controllers\Web\HintController;
use Illuminate\Support\Facades\Route;

// Indicii de primă vizită respinse — BR-HELP-02. Inclus din routes/web.php, autentificat, FĂRĂ workspace:
// cheia e per UTILIZATOR (users.dismissed_hints), nu per tenant, deci nu are nevoie de {workspace}.
Route::post('/hints/{key}', [HintController::class, 'dismiss'])->name('hints.dismiss');
